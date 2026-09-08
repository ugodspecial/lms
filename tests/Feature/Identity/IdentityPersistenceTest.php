<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Enums\ConnectedProvider;
use App\Domain\Identity\Enums\ConnectedPurpose;
use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Consent;
use App\Domain\Identity\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * Identity records as they actually persist (docs/02 §1, §58, §9).
 *
 * Three things are being pinned here, and all three fail silently if they are
 * wrong:
 *
 * UUID generation, because a NULL uuid column produces a row that cannot be
 * referenced in a URL and no error until somebody builds one.
 *
 * Deletion semantics, because `users` is referenced with ON DELETE RESTRICT by
 * every academic and financial table: a hard delete either fails or orphans a
 * child's history, and the difference between "deactivated" and "gone" is the
 * whole of §58.
 *
 * Consent, because a consent record that can be edited or deleted cannot prove
 * anything, and this platform holds children's data.
 *
 * The unit-level rules — what is fillable, what is hidden, which casts exist — are
 * in UserModelTest and ConnectedAccountModelTest.
 */
final class IdentityPersistenceTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function test_the_factory_reaches_a_model_that_lives_in_a_domain_namespace(): void
    {
        // The framework's default guesser maps App\Domain\Identity\Models\User to
        // Database\Factories\Domain\Identity\Models\UserFactory, which does not
        // exist. #[UseFactory] is what makes User::factory() work at all, and
        // #[UseModel] is what makes the factory know its model.
        $this->assertSame(User::class, UserFactory::new()->modelName());

        $user = User::factory()->create();

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue($user->exists);

        // Asserted against this user rather than a global count: the suite runs on a
        // seeded database (see Tests\TestCase), so "how many rows are there" is not
        // a question a test owns.
        $this->assertDatabaseHas('users', ['email' => $user->email]);
        $this->assertNotNull(
            $user->email_verified_at,
            'a factory constructs inside Model::unguarded(), so it sets columns the fillable list refuses to a request'
        );
    }

    public function test_a_user_is_given_a_uuid_on_creation_and_keeps_one_it_is_given(): void
    {
        $user = $this->makeUser();

        $this->assertMatchesRegularExpression(self::UUID, $user->uuid);

        // An idempotent import or a replayed webhook has to be able to reuse a
        // UUID rather than mint a second row, which only works if an explicit one
        // survives creation. Built without the factory so this is a genuine insert.
        $fixed = '11111111-2222-3333-4444-555555555555';

        $imported = new User;
        $imported->uuid = $fixed;
        $imported->name = 'Imported Person';
        $imported->email = 'imported@example.test';
        $imported->save();

        $this->assertSame($fixed, $imported->uuid);
        $this->assertDatabaseHas('users', ['uuid' => $fixed, 'email' => 'imported@example.test']);
    }

    public function test_deleting_a_user_deactivates_the_row_instead_of_removing_it(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $this->assertNotNull($user->deleted_at);
        $this->assertNull(User::find($user->id), 'a soft-deleted user is gone from every normal query');
        $this->assertNotNull(User::withTrashed()->find($user->id));
        $this->assertTrue(
            DB::table('users')->where('id', $user->id)->exists(),
            'the row is still there: academic and financial records reference it with ON DELETE RESTRICT'
        );
    }

    public function test_the_moment_columns_come_back_as_moments(): void
    {
        $user = $this->makeUser([
            'last_login_at' => Carbon::parse('2026-09-08 14:30:00'),
            'status' => UserStatus::Active,
        ])->fresh();

        $this->assertInstanceOf(Carbon::class, $user->email_verified_at);
        $this->assertInstanceOf(Carbon::class, $user->last_login_at);
        $this->assertSame('2026-09-08 14:30:00', $user->last_login_at->format('Y-m-d H:i:s'));
        $this->assertSame(UserStatus::Active, $user->status);
    }

    public function test_a_users_consents_and_provider_links_are_only_their_own(): void
    {
        $ada = $this->makeUser();
        $other = $this->makeUser();

        $this->makeConsent($ada, ConsentType::Terms);
        $this->makeConsent($ada, ConsentType::Privacy);
        $this->makeConsent($other, ConsentType::Terms);

        $this->makeLink($ada, ConnectedProvider::Microsoft, ConnectedPurpose::Login);
        $this->makeLink($other, ConnectedProvider::Microsoft, ConnectedPurpose::Login);

        $this->assertSame(2, $ada->consents()->count());
        $this->assertSame(1, $ada->connectedAccounts()->count());
        $this->assertSame(1, $ada->consents()->granted()->ofType(ConsentType::Privacy)->count());

        foreach ($ada->consents as $consent) {
            $this->assertTrue($consent->user->is($ada));
        }
    }

    public function test_owning_a_file_and_uploading_it_are_different_relationships(): void
    {
        $student = $this->makeUser();
        $administrator = $this->makeUser();

        // The normal case, not the exception: staff upload documents that belong to
        // a student. Conflating the two relations is how a student's file ends up
        // listed as the administrator's, or an administrator's upload becomes
        // invisible to the person it describes.
        $file = $this->makeFile(uploader: $administrator, owner: $student);

        $this->assertTrue($student->files()->whereKey($file->id)->exists(), 'owned by the student');
        $this->assertTrue($administrator->uploads()->whereKey($file->id)->exists(), 'uploaded by the administrator');
        $this->assertFalse($student->uploads()->whereKey($file->id)->exists(), 'owning is not uploading');
        $this->assertFalse($administrator->files()->whereKey($file->id)->exists(), 'uploading is not owning');
        $this->assertTrue($file->uploader->is($administrator));
    }

    public function test_an_avatar_is_reachable_through_its_foreign_key(): void
    {
        $user = $this->makeUser();
        $file = $this->makeFile(uploader: $user, owner: $user);

        $user->avatar_file_id = $file->id;
        $user->save();

        $this->assertTrue($user->fresh()->avatar->is($file));
    }

    public function test_a_users_audit_trail_is_what_they_caused_not_what_happened_to_them(): void
    {
        $administrator = $this->makeUser();
        $subject = $this->makeUser();

        $this->makeAuditLog('users.status_changed', actor: $administrator, subject: $subject);

        $this->assertSame(1, $administrator->auditLogs()->count());
        $this->assertSame(
            0,
            $subject->auditLogs()->count(),
            'being the subject of an entry must not make it appear in your own trail'
        );
    }

    public function test_one_provider_can_be_linked_once_per_purpose(): void
    {
        $user = $this->makeUser();

        // The whole reason the unique key is (user_id, provider, purpose): signing
        // in with Google and provisioning Meet rooms need different scopes and
        // different tokens, and disconnecting one must not break the other.
        $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Login);
        $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Meetings);
        $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Calendar);

        $this->assertSame(3, $user->connectedAccounts()->count());
    }

    public function test_the_same_provider_and_purpose_cannot_be_linked_twice(): void
    {
        $user = $this->makeUser();

        $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Login);

        $this->expectException(QueryException::class);

        $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Login);
    }

    public function test_a_stored_provider_token_is_not_readable_from_the_database(): void
    {
        $user = $this->makeUser();
        $plaintext = 'ya29.a0AfH6SM-this-is-an-access-token';

        $account = $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Meetings, [
            'access_token' => $plaintext,
            'refresh_token' => 'refresh-token-plaintext',
        ]);

        $row = DB::table('connected_accounts')->where('id', $account->id)->first();

        // A database dump must not be enough to act as somebody's Google account
        // (§58). The app key is the only thing between a leaked dump and a leaked
        // calendar, so this asserts the ciphertext is what is actually stored.
        $this->assertStringNotContainsString($plaintext, (string) $row->access_token);
        $this->assertStringNotContainsString('refresh-token-plaintext', (string) $row->refresh_token);

        // And it must still come back exactly, or the integration is useless.
        $fresh = $account->fresh();
        $this->assertSame($plaintext, $fresh->access_token);
        $this->assertSame('refresh-token-plaintext', $fresh->refresh_token);
    }

    public function test_scopes_are_stored_exactly_as_the_provider_returned_them(): void
    {
        $user = $this->makeUser();
        $scopes = 'openid email profile https://www.googleapis.com/auth/calendar.events';

        $account = $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Calendar, [
            'scopes' => $scopes,
        ]);

        // Space-delimited on the wire and compared as a whole. A JSON cast here
        // would quietly reformat it and every scope comparison against a provider
        // response would start failing.
        $this->assertSame($scopes, $account->fresh()->scopes);
        $this->assertSame($scopes, DB::table('connected_accounts')->where('id', $account->id)->value('scopes'));
    }

    public function test_consent_is_recorded_with_the_evidence_that_goes_with_it(): void
    {
        $user = $this->makeUser();

        $consent = $this->makeConsent($user, ConsentType::DataProcessing)->refresh();

        $this->assertSame(ConsentType::DataProcessing, $consent->type);
        $this->assertTrue($consent->granted);
        $this->assertSame('2026-09-01', $consent->version);
        $this->assertSame('203.0.113.7', $consent->ip_address);
        $this->assertSame('PHPUnit', $consent->user_agent);
        $this->assertInstanceOf(
            Carbon::class,
            $consent->recorded_at,
            'the moment of the decision is stamped by the database, not left to the caller'
        );
    }

    public function test_a_consent_row_has_no_updated_at_column(): void
    {
        $user = $this->makeUser();
        $consent = $this->makeConsent($user);

        $this->assertFalse(Schema::hasColumn('consents', 'updated_at'));
        $this->assertFalse($consent->timestamps);
        $this->assertArrayNotHasKey('updated_at', $consent->getAttributes());
    }

    public function test_withdrawing_consent_adds_a_row_and_leaves_the_original_intact(): void
    {
        $user = $this->makeUser();

        $this->makeConsent($user, ConsentType::Marketing, granted: true);
        $this->makeConsent($user, ConsentType::Marketing, granted: false);

        $history = $user->consents()->ofType(ConsentType::Marketing)->orderBy('id')->get();

        $this->assertCount(2, $history);
        $this->assertTrue($history[0]->granted, 'the decision that was made is still on record');
        $this->assertFalse($history[1]->granted, 'and so is the one that replaced it');
        $this->assertSame(1, $user->consents()->granted()->ofType(ConsentType::Marketing)->count());
    }

    public function test_an_existing_consent_record_cannot_be_edited(): void
    {
        $user = $this->makeUser();
        $consent = $this->makeConsent($user, ConsentType::Terms, granted: true);

        $consent->granted = false;

        try {
            $consent->save();
            $this->fail('editing a consent record must throw rather than quietly rewriting history');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertTrue(
            Consent::find($consent->id)->granted,
            'the stored decision is unchanged: the refusal happened before any write'
        );
    }

    public function test_a_consent_record_cannot_be_deleted_one_at_a_time(): void
    {
        $user = $this->makeUser();
        $consent = $this->makeConsent($user);

        try {
            $consent->delete();
            $this->fail('a consent record is evidence and must not be deletable through the model');
        } catch (LogicException $e) {
            $this->assertStringContainsString('evidence', $e->getMessage());
        }

        $this->assertDatabaseHas('consents', ['id' => $consent->id]);
    }
}
