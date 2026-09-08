<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Enums\AuthProvider;
use App\Domain\Identity\Enums\UserCreatedBy;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

/**
 * The rules the identity model itself enforces, with no database involved.
 *
 * These are the assertions that keep a small edit from becoming a security hole.
 * Widening `$fillable` by one column, adding a "helpful" cast, or dropping
 * `implements MustVerifyEmail` all compile, all pass a code review at a glance,
 * and each changes what an untrusted request can do to an account.
 *
 * Nothing here touches the database; the persisted behaviour — UUID generation,
 * soft deletes, relations — is in IdentityPersistenceTest.
 */
final class UserModelTest extends TestCase
{
    public function test_the_fillable_list_is_exactly_the_self_service_profile_fields(): void
    {
        // name, email, password, timezone, locale: what a profile form may post.
        // Anything else on the row is written by code that has already decided the
        // caller may write it (docs/02 §1, §55).
        $this->assertSame(
            ['name', 'email', 'password', 'timezone', 'locale'],
            (new User)->getFillable()
        );
    }

    public function test_a_request_cannot_set_its_own_account_status(): void
    {
        // A self-service form that could set `status` would let a suspended account
        // reactivate itself by posting one extra field.
        $this->expectException(MassAssignmentException::class);

        (new User)->fill(['name' => 'A', 'status' => UserStatus::Active->value]);
    }

    public function test_a_request_cannot_mark_its_own_email_verified(): void
    {
        // This is the defect that `preventSilentlyDiscardingAttributes()` was
        // enabled for: DemoUserSeeder posted `email_verified_at` and the column
        // was silently dropped, producing accounts that looked seeded and were
        // refused at the verification gate. Discarded silently or thrown, the
        // attribute must never land.
        $this->expectException(MassAssignmentException::class);

        (new User)->fill(['email' => 'a@b.c', 'email_verified_at' => now()]);
    }

    public function test_a_request_cannot_point_its_avatar_at_another_users_file(): void
    {
        // `avatar_file_id` is a foreign key to a possibly private file. Settable by
        // request, it becomes a way to render somebody else's document on your own
        // profile page and read it back.
        $this->expectException(MassAssignmentException::class);

        (new User)->fill(['name' => 'A', 'avatar_file_id' => 1]);
    }

    public function test_status_provider_and_origin_are_read_as_enums_not_strings(): void
    {
        $user = new User;
        $user->status = UserStatus::Suspended->value;
        $user->auth_provider = AuthProvider::Google->value;
        $user->created_by_type = UserCreatedBy::ByOAuth->value;

        $this->assertSame(UserStatus::Suspended, $user->status);
        $this->assertSame(AuthProvider::Google, $user->auth_provider);
        $this->assertSame(UserCreatedBy::ByOAuth, $user->created_by_type);
    }

    public function test_only_an_active_account_reports_itself_active(): void
    {
        foreach (UserStatus::cases() as $status) {
            $user = new User;
            $user->status = $status;

            $this->assertSame($status === UserStatus::Active, $user->isActive());
        }
    }

    public function test_two_factor_counts_as_confirmed_only_once_a_code_has_been_proven(): void
    {
        // Switching 2FA on and proving you can read a code are two events. Between
        // them the account is not protected, and an interface that shows a padlock
        // for the flag alone is telling the user something false.
        $user = new User;
        $user->two_factor_enabled = true;

        $this->assertFalse($user->hasConfirmedTwoFactor(), 'enabled but never confirmed');

        $user->two_factor_confirmed_at = now();
        $this->assertTrue($user->hasConfirmedTwoFactor());

        $user->two_factor_enabled = false;
        $this->assertFalse($user->hasConfirmedTwoFactor(), 'confirmed then switched off');
    }

    public function test_the_two_factor_columns_are_left_for_fortify_to_encrypt(): void
    {
        /*
         * Fortify's TwoFactorAuthenticatable encrypts and decrypts these columns
         * itself. An `encrypted` Eloquent cast would encrypt a second time on
         * write, and the failure would not appear until somebody tried to log in
         * with a code — long after the row was unreadable.
         *
         * Asserted on the RAW stored attribute rather than on getCasts(): what
         * matters is that the value Fortify later reads is the plaintext it wrote,
         * and that is a statement about the column, not about the cast list.
         */
        $secret = 'JBSWY3DPEHPK3PXP';

        $user = new User;
        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = '["apple-banana"]';

        $this->assertSame($secret, $user->getAttributes()['two_factor_secret']);
        $this->assertSame('["apple-banana"]', $user->getAttributes()['two_factor_recovery_codes']);
    }

    public function test_an_oauth_only_account_reports_that_it_has_no_password(): void
    {
        // A NULL password is not a failed password check. A reset flow that treated
        // it as one would tell a working Google-signed-in account that its
        // credentials were wrong.
        $this->assertFalse((new User)->hasPassword());

        $withPassword = new User;
        $withPassword->password = 'correct-horse-battery-staple';

        $this->assertTrue($withPassword->hasPassword());
        $this->assertNotSame(
            'correct-horse-battery-staple',
            $withPassword->getAttributes()['password'],
            'the hashed cast must store a hash, never the plaintext'
        );
    }

    public function test_an_empty_password_from_a_legacy_import_is_not_treated_as_one(): void
    {
        // Unreachable through normal assignment — the `hashed` cast would hash the
        // empty string — but an import or a hand-run query can produce it, and an
        // empty password that compared equal to an empty submission would be the
        // worst authentication bug available. Set raw to reach that state.
        $user = new User;
        $user->setRawAttributes(['password' => '']);

        $this->assertFalse($user->hasPassword());
    }

    public function test_secret_columns_never_reach_a_serialised_user(): void
    {
        // An API resource or a Livewire payload that forgets to trim its fields
        // must still not be able to leak these. Hidden on the model is the last
        // line, not the first (§55, §77).
        $user = new User;
        $user->setRawAttributes([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => '$2y$10$secrethashvalue',
            'remember_token' => 'remember-token-value',
            'two_factor_secret' => 'totp-secret-value',
            'two_factor_recovery_codes' => '["recovery-code-value"]',
        ]);

        $array = $user->toArray();

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'] as $column) {
            $this->assertArrayNotHasKey($column, $array);
        }

        $this->assertArrayHasKey('email', $array, 'a usable payload, not an empty one');

        $json = $user->toJson();
        $this->assertStringNotContainsString('secrethashvalue', $json);
        $this->assertStringNotContainsString('totp-secret-value', $json);
        $this->assertStringNotContainsString('recovery-code-value', $json);
        $this->assertStringNotContainsString('remember-token-value', $json);
    }

    public function test_email_verification_is_contractually_required_of_every_user(): void
    {
        // Laravel 13's base user class supplies the MustVerifyEmail methods but
        // does not declare the contract, so the `verified` middleware — which tests
        // `instanceof` — would pass every unverified account straight through
        // without this line. Methods present, enforcement absent.
        $this->assertInstanceOf(MustVerifyEmail::class, new User);
    }

    public function test_a_user_is_soft_deleted_rather_than_erased(): void
    {
        // Academic and financial rows reference `users` with ON DELETE RESTRICT
        // (docs/04 §66.10), so `delete()` has to mean "deactivate", and erasure
        // under §58 is a separate, deliberate process.
        $this->assertContains(SoftDeletes::class, class_uses_recursive(User::class));
        $this->assertSame('deleted_at', (new User)->getDeletedAtColumn());
    }
}
