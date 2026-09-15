<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Enums\AuthProvider;
use App\Domain\Identity\Enums\ConnectedAccountStatus;
use App\Domain\Identity\Enums\ConnectedProvider;
use App\Domain\Identity\Enums\ConnectedPurpose;
use App\Domain\Identity\Enums\UserCreatedBy;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\ConnectedAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OAuthLinkingService;
use App\Exceptions\PlatformException;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Two\User as ProviderUser;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * Linking an external identity to an account here, and unlinking it (docs/07 W5).
 *
 * A provider handshake proves somebody controls an account at Google or Microsoft.
 * It does not prove which account HERE that is, and every refusal asserted below is
 * a place where those two questions could be confused:
 *
 *   • an identity already claimed by another user, which is account takeover with a
 *     consent screen in front of it;
 *   • an address the provider reports as unverified, which is how two people end up
 *     behind one login;
 *   • an address held by a closed account, which would otherwise collide with the
 *     unique index and arrive as a QueryException nobody can act on.
 *
 * The other half of this file is about what is STORED. Tokens are encrypted at rest
 * and never appear in an audit entry, because a database dump or an audit viewer
 * must not be enough to act as somebody's Google account (§58) — and an audit log is
 * read by more people, more often, than the table it describes.
 */
final class OAuthLinkingTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    public function test_a_link_is_stored_with_its_tokens_encrypted_at_rest(): void
    {
        $user = $this->makeUser();

        $account = $this->service()->link(
            $user,
            $this->providerUser(['id' => 'google-1', 'email' => 'person@example.test']),
            ConnectedProvider::Google,
        );

        $this->assertSame(ConnectedAccountStatus::Connected, $account->status);
        $this->assertSame(ConnectedPurpose::Login, $account->purpose);
        $this->assertSame('person@example.test', $account->provider_email);
        $this->assertNotNull($account->last_used_at);

        // Read through the model, then read the column. The first is what the
        // application sees; the second is what a database dump contains.
        $this->assertSame('access-token', $account->access_token);
        $this->assertSame('refresh-token', $account->refresh_token);

        $stored = self::rawRow($account);

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('access-token', (string) $stored->access_token);
        $this->assertStringNotContainsString('refresh-token', (string) $stored->refresh_token);
    }

    public function test_signing_in_again_refreshes_the_link_instead_of_adding_a_second_one(): void
    {
        $user = $this->makeUser();
        $providerUser = $this->providerUser(['id' => 'google-1', 'email' => 'person@example.test']);

        $this->service()->link($user, $providerUser, ConnectedProvider::Google);
        $this->service()->link($user, $providerUser, ConnectedProvider::Google);

        // Two rows for one identity would mean two answers to "which token do we use
        // for this person", and the unique key on (user, provider, purpose) is what
        // makes that impossible rather than merely unlikely.
        $this->assertSame(1, ConnectedAccount::query()->where('user_id', $user->id)->count());

        $refreshed = $this->providerUser(
            ['id' => 'google-1', 'email' => 'person@example.test'],
            token: 'a-newer-token',
        );

        $account = $this->service()->link($user, $refreshed, ConnectedProvider::Google);

        $this->assertSame('a-newer-token', $account->access_token);
        $this->assertSame(1, ConnectedAccount::query()->where('user_id', $user->id)->count());
    }

    public function test_one_identity_cannot_be_claimed_by_a_second_user(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $providerUser = $this->providerUser(['id' => 'google-1', 'email' => 'person@example.test']);

        $this->service()->link($first, $providerUser, ConnectedProvider::Google);

        try {
            $this->service()->link($second, $providerUser, ConnectedProvider::Google);

            $this->fail('That identity is already claimed.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.provider_already_linked', $exception->getErrorCode());
            $this->assertSame(409, $exception->getStatusCode());

            // Which account holds the identity is not something a stranger at a
            // consent screen should be able to learn from the refusal.
            $this->assertStringNotContainsString($first->email, $exception->getUserMessage());
            $this->assertStringNotContainsString($first->email, json_encode($exception->getErrors()) ?: '');
        }

        $this->assertSame(1, ConnectedAccount::query()->where('provider_user_id', 'google-1')->count());
    }

    public function test_a_revoked_link_does_not_keep_the_identity_out_of_reach(): void
    {
        // Re-linking means completing the provider's consent again, and that
        // handshake is the proof of control — not the row. A revoked row is history,
        // and treating it as a permanent claim would make a disconnected account
        // unrecoverable without a database edit.
        // With a password, so that disconnecting this link is itself allowed: the
        // last-way-in rule would otherwise refuse the very revocation this test needs.
        $first = $this->makeUser();
        $account = $this->makeLink($first);

        $this->service()->disconnect($account, $first);

        $second = $this->makeUser();

        $linked = $this->service()->link(
            $second,
            $this->providerUser(['id' => $account->provider_user_id, 'email' => 'person@example.test']),
            ConnectedProvider::Google,
        );

        $this->assertSame(ConnectedAccountStatus::Connected, $linked->status);
        $this->assertSame($second->id, (int) $linked->user_id);
    }

    public function test_an_address_the_provider_reports_as_unverified_is_refused(): void
    {
        $user = $this->makeUser();

        try {
            $this->service()->link(
                $user,
                $this->providerUser([
                    'id' => 'google-1',
                    'email' => 'unproved@example.test',
                    'email_verified' => false,
                ]),
                ConnectedProvider::Google,
            );

            $this->fail('Linking on an unverified address is how two people share one login.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.provider_email_unverified', $exception->getErrorCode());
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame(0, ConnectedAccount::query()->count());
    }

    public function test_a_provider_that_says_nothing_about_verification_is_accepted_and_the_silence_is_recorded(): void
    {
        // Microsoft's user object carries no `email_verified` claim. Refusing on
        // "the provider said nothing" would make a configured provider unusable,
        // which is the failure §93 exists to prevent — so the link is accepted and
        // the absence is written down where an investigation can find it.
        $user = $this->makeUser();

        $account = $this->service()->link(
            $user,
            $this->providerUser(['id' => 'microsoft-1', 'email' => 'person@example.test']),
            ConnectedProvider::Microsoft,
        );

        $this->assertSame(ConnectedAccountStatus::Connected, $account->status);
        $this->assertNull($account->meta['email_verified_claim']);
    }

    public function test_a_verification_claim_sent_as_a_string_is_read_as_the_boolean_it_means(): void
    {
        $user = $this->makeUser();

        $accepted = $this->service()->link(
            $user,
            $this->providerUser([
                'id' => 'google-1',
                'email' => 'person@example.test',
                'email_verified' => 'true',
            ]),
            ConnectedProvider::Google,
        );

        $this->assertTrue($accepted->meta['email_verified_claim']);

        try {
            $this->service()->link(
                $this->makeUser(),
                $this->providerUser([
                    'id' => 'google-2',
                    'email' => 'other@example.test',
                    'email_verified' => 'false',
                ]),
                ConnectedProvider::Google,
            );

            $this->fail('The string "false" is the same claim as false.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.provider_email_unverified', $exception->getErrorCode());
        }
    }

    public function test_the_address_is_normalised_before_anything_is_matched_on_it(): void
    {
        $user = $this->makeUser(['email' => 'person@example.test']);

        $account = $this->service()->link(
            $user,
            $this->providerUser(['id' => 'google-1', 'email' => '  Person@Example.TEST  ']),
            ConnectedProvider::Google,
        );

        // `users.email` is unique and lowercase (docs/02 §1). Matching on the raw
        // value would create a second account for one person, and the unique index
        // would then refuse the sign-in with a database error.
        $this->assertSame('person@example.test', $account->provider_email);
    }

    public function test_a_login_link_records_how_the_account_signs_in_and_a_meetings_link_does_not(): void
    {
        $tutor = $this->makeUser();

        $this->service()->link(
            $tutor,
            $this->providerUser(['id' => 'google-1', 'email' => 'tutor@example.test']),
            ConnectedProvider::Google,
            ConnectedPurpose::Meetings,
        );

        // A meetings link is a capability, not a credential. Letting it change how
        // the person signs in would be a scope escalation nobody consented to.
        $this->assertSame(AuthProvider::Password, $tutor->refresh()->auth_provider);

        $this->service()->link(
            $tutor,
            $this->providerUser(['id' => 'google-1', 'email' => 'tutor@example.test']),
            ConnectedProvider::Google,
            ConnectedPurpose::Login,
        );

        $this->assertSame(AuthProvider::Google, $tutor->refresh()->auth_provider);

        // And both links exist side by side, which is the reason the unique key
        // includes purpose at all.
        $this->assertSame(2, ConnectedAccount::query()->where('user_id', $tutor->id)->count());
    }

    public function test_a_zoom_connection_is_never_treated_as_a_way_to_sign_in(): void
    {
        $tutor = $this->makeUser();

        $account = $this->service()->link(
            $tutor,
            $this->providerUser(['id' => 'zoom-1', 'email' => 'tutor@example.test']),
            ConnectedProvider::Zoom,
            ConnectedPurpose::Meetings,
        );

        $this->assertSame(ConnectedProvider::Zoom, $account->provider);
        $this->assertSame(AuthProvider::Password, $tutor->refresh()->auth_provider);
    }

    public function test_the_audit_entry_names_the_link_and_contains_no_token(): void
    {
        $user = $this->makeUser();

        $this->service()->link(
            $user,
            $this->providerUser(['id' => 'google-1', 'email' => 'person@example.test']),
            ConnectedProvider::Google,
            scopes: 'openid email profile',
        );

        $entry = AuditLog::query()->where('event', 'auth.oauth_linked')->latest('id')->first();

        $this->assertInstanceOf(AuditLog::class, $entry);
        $this->assertSame(ConnectedAccount::class, $entry->auditable_type);
        $this->assertSame('google', $entry->new_values['provider']);
        $this->assertSame('login', $entry->new_values['purpose']);
        $this->assertSame('openid email profile', $entry->new_values['scopes']);
        $this->assertContains('oauth', $entry->tagList());

        // The scrubber would blank a key named access_token. This asserts the
        // stronger property: the value was never handed to it, so there is nothing
        // for a misconfigured redaction list to leak.
        $recorded = json_encode(['old' => $entry->old_values, 'new' => $entry->new_values]) ?: '';

        $this->assertStringNotContainsString('access-token', $recorded);
        $this->assertStringNotContainsString('refresh-token', $recorded);
        $this->assertArrayNotHasKey('access_token', (array) $entry->new_values);
        $this->assertArrayNotHasKey('refresh_token', (array) $entry->new_values);
    }

    public function test_a_sign_in_with_an_existing_link_returns_that_account_and_refreshes_it(): void
    {
        $user = $this->makeUser();
        $account = $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Login, [
            'provider_user_id' => 'google-1',
            'access_token' => 'an-old-token',
        ]);

        $resolved = $this->service()->findOrCreateForLogin(
            ConnectedProvider::Google,
            $this->providerUser(['id' => 'google-1', 'email' => 'whoever@example.test'], token: 'a-new-token'),
        );

        // The order of the lookups is the security argument: an identity already
        // claimed wins before an address is ever compared, so a provider account
        // cannot be steered to a different user by changing the address it reports.
        $this->assertSame($user->id, $resolved->id);
        $this->assertSame('a-new-token', $account->refresh()->access_token);
        $this->assertSame(1, User::query()->count());
    }

    public function test_a_sign_in_whose_address_matches_an_existing_account_links_rather_than_duplicates(): void
    {
        $user = $this->makeUser(['email' => 'person@example.test']);

        $resolved = $this->service()->findOrCreateForLogin(
            ConnectedProvider::Google,
            $this->providerUser(['id' => 'google-1', 'email' => 'PERSON@example.test', 'email_verified' => true]),
        );

        $this->assertSame($user->id, $resolved->id);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, ConnectedAccount::query()->where('user_id', $user->id)->count());
        $this->assertSame(AuthProvider::Google, $resolved->refresh()->auth_provider);
    }

    public function test_a_sign_in_for_a_new_verified_address_creates_an_account_that_is_ready_to_use(): void
    {
        $created = $this->service()->findOrCreateForLogin(
            ConnectedProvider::Google,
            $this->providerUser([
                'id' => 'google-1',
                'email' => 'new.person@example.test',
                'name' => 'New Person',
                'email_verified' => true,
            ]),
        );

        $this->assertSame(UserStatus::Active, $created->status);
        $this->assertNotNull($created->email_verified_at);
        $this->assertSame('New Person', $created->name);
        $this->assertSame(AuthProvider::Google, $created->auth_provider);
        $this->assertSame(UserCreatedBy::ByOAuth, $created->created_by_type);
        $this->assertFalse($created->hasPassword());
        $this->assertNotEmpty($created->uuid);

        $this->assertSame(1, ConnectedAccount::query()->where('user_id', $created->id)->count());
        $this->assertNotNull(
            AuditLog::query()->where('event', 'auth.oauth_registered')->where('auditable_id', $created->id)->first(),
        );
    }

    public function test_a_sign_in_the_provider_did_not_verify_creates_a_pending_account_and_asks_for_verification(): void
    {
        // W5: "Email not verified at the provider → fall back to a verification
        // email before linking". The account exists and can be signed into, and
        // still cannot reach a portal until the address is proved here.
        Notification::fake();

        $created = $this->service()->findOrCreateForLogin(
            ConnectedProvider::Microsoft,
            $this->providerUser([
                'id' => 'microsoft-1',
                'email' => 'unproved@example.test',
                'name' => 'Unproved Person',
                'email_verified' => false,
            ]),
        );

        $this->assertSame(UserStatus::Pending, $created->status);
        $this->assertNull($created->email_verified_at);

        Notification::assertSentTo($created, VerifyEmail::class);
    }

    public function test_a_created_account_is_given_no_role(): void
    {
        // A person is not a student, parent, tutor or evaluator until a profile says
        // so (§6). Guessing one here would hand a stranger portal access on the
        // strength of an email address.
        $created = $this->service()->findOrCreateForLogin(
            ConnectedProvider::Google,
            $this->providerUser(['id' => 'google-1', 'email' => 'new.person@example.test', 'email_verified' => true]),
        );

        $this->assertSame([], $created->getRoleNames()->all());
        $this->assertFalse($created->can('student.portal.access'));
        $this->assertFalse($created->can('admin.panel.access'));
    }

    public function test_an_address_held_by_a_closed_account_is_refused_rather_than_duplicated(): void
    {
        $closed = $this->makeUser(['email' => 'closed@example.test']);
        $closed->delete();

        try {
            $this->service()->findOrCreateForLogin(
                ConnectedProvider::Google,
                $this->providerUser(['id' => 'google-1', 'email' => 'closed@example.test', 'email_verified' => true]),
            );

            $this->fail('The address is still taken, including by a soft-deleted row.');
        } catch (PlatformException $exception) {
            // The alternative is a QueryException from the unique index, which tells
            // the person signing in nothing and tells support nothing either.
            $this->assertSame('identity.email_held_by_closed_account', $exception->getErrorCode());
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(1, User::withTrashed()->where('email', 'closed@example.test')->count());
    }

    public function test_a_provider_that_shares_no_address_cannot_create_an_account(): void
    {
        try {
            $this->service()->findOrCreateForLogin(
                ConnectedProvider::Google,
                $this->providerUser(['id' => 'google-1', 'email_verified' => true]),
            );

            $this->fail('There is nothing to match an account on and nothing to verify.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.provider_email_missing', $exception->getErrorCode());
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertArrayHasKey('email', $exception->getErrors());
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_provider_that_returns_no_identifier_links_nothing(): void
    {
        $user = $this->makeUser();

        try {
            $this->service()->link($user, $this->providerUser(['email' => 'person@example.test']), ConnectedProvider::Google);

            $this->fail('Without a subject identifier the row would match every future sign-in, or none.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.provider_identity_missing', $exception->getErrorCode());
        }

        $this->assertSame(0, ConnectedAccount::query()->count());
    }

    public function test_disconnecting_clears_the_tokens_and_records_the_revocation(): void
    {
        $user = $this->makeUser();

        // Built with both tokens, because the assertion that matters is the one
        // about the refresh token: it is the credential that outlives the session,
        // and a test that never set one would pass without proving anything.
        $account = $this->makeLink($user, overrides: [
            'refresh_token' => 'a-refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertTrue($account->canRefresh());

        $this->service()->disconnect($account, $user);

        $refreshed = $account->refresh();

        $this->assertSame(ConnectedAccountStatus::Revoked, $refreshed->status);
        $this->assertNull($refreshed->access_token);
        $this->assertNull($refreshed->refresh_token);
        $this->assertFalse($refreshed->isUsable());
        $this->assertFalse($refreshed->canRefresh());

        // The status is what the platform consults; the tokens are what an attacker
        // with a dump consults. A revoked row that still held a working refresh token
        // would be a revocation in name only.
        $stored = self::rawRow($account);

        $this->assertNotNull($stored);
        $this->assertNull($stored->access_token);
        $this->assertNull($stored->refresh_token);

        $entry = AuditLog::query()->where('event', 'auth.oauth_unlinked')->latest('id')->first();

        $this->assertInstanceOf(AuditLog::class, $entry);
        $this->assertSame('connected', $entry->old_values['status']);
        $this->assertSame('revoked', $entry->new_values['status']);

        // assertTrue rather than assertArrayHasKey: a key the scrubber ate would
        // still be present, holding '[REDACTED]'. These two names are deliberately
        // free of `token` — see the note at the audit call in disconnect().
        $this->assertTrue($entry->old_values['refresh_credential_present']);
        $this->assertTrue($entry->new_values['credentials_cleared']);
    }

    public function test_the_last_way_into_an_account_cannot_be_disconnected(): void
    {
        // Not in the plan as a rule, and included because the alternative is an
        // account nobody can reach — including support, whose only remaining option
        // would be an unrecorded database edit to an identity row.
        $user = $this->makeUser(['password' => null]);
        $account = $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Login);

        try {
            $this->service()->disconnect($account, $user);

            $this->fail('That is the only credential this account has.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.last_login_method', $exception->getErrorCode());
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(ConnectedAccountStatus::Connected, $account->refresh()->status);
    }

    public function test_a_login_link_can_be_disconnected_once_a_password_exists(): void
    {
        $user = $this->makeUser();
        $account = $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Login);

        $this->assertTrue($user->hasPassword());

        $this->service()->disconnect($account, $user);

        $this->assertSame(ConnectedAccountStatus::Revoked, $account->refresh()->status);
    }

    public function test_one_of_two_login_links_can_be_disconnected_without_a_password(): void
    {
        $user = $this->makeUser(['password' => null]);
        $google = $this->makeLink($user, ConnectedProvider::Google, ConnectedPurpose::Login);
        $microsoft = $this->makeLink($user, ConnectedProvider::Microsoft, ConnectedPurpose::Login);

        $this->service()->disconnect($google, $user);

        $this->assertSame(ConnectedAccountStatus::Revoked, $google->refresh()->status);
        $this->assertSame(ConnectedAccountStatus::Connected, $microsoft->refresh()->status);
    }

    public function test_a_meetings_link_is_not_the_last_way_in_question(): void
    {
        // Disconnecting a calendar integration has to stay a free choice: it ends a
        // capability, not a credential, and blocking it would leave a tutor unable to
        // remove an integration they no longer want.
        $tutor = $this->makeUser(['password' => null]);
        $meetings = $this->makeLink($tutor, ConnectedProvider::Google, ConnectedPurpose::Meetings);

        $this->service()->disconnect($meetings, $tutor);

        $this->assertSame(ConnectedAccountStatus::Revoked, $meetings->refresh()->status);
    }

    public function test_somebody_elses_connection_cannot_be_disconnected_without_the_right_to_administer_users(): void
    {
        $owner = $this->makeUser();
        $account = $this->makeLink($owner);

        $stranger = $this->makeUser();

        try {
            $this->service()->disconnect($account, $stranger);

            $this->fail('That connection belongs to somebody else.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.connected_account_not_yours', $exception->getErrorCode());
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(ConnectedAccountStatus::Connected, $account->refresh()->status);

        $administrator = $this->makeUser();
        $administrator->assignRole(Roles::ADMINISTRATOR);

        $this->assertTrue($administrator->can('users.update'));

        $this->service()->disconnect($account, $administrator);

        $this->assertSame(ConnectedAccountStatus::Revoked, $account->refresh()->status);
    }

    /**
     * The row as the database holds it, bypassing the model's casts.
     *
     * `where('id', ...)` and not `whereKey(...)`: on a query builder there is no
     * such method, and `Query\Builder::__call` reads `whereKey` as a dynamic where
     * and asks MySQL for a column called `key`. It failed loudly here, but the same
     * mistake against a table that happens to have a `key` column would have
     * compared the wrong rows and passed.
     */
    private static function rawRow(ConnectedAccount $account): ?object
    {
        return DB::table('connected_accounts')->where('id', $account->getKey())->first();
    }

    private function service(): OAuthLinkingService
    {
        return app(OAuthLinkingService::class);
    }

    /**
     * A provider user as Socialite hands one over.
     *
     * Built rather than mocked: `map()` and `setRaw()` are both on the contract, and
     * calling the two of them means the fixture does not depend on which of them a
     * given Socialite version uses to populate `raw`. Tokens go on the public
     * properties the package documents.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function providerUser(
        array $attributes = [],
        ?string $token = 'access-token',
        ?string $refreshToken = 'refresh-token',
    ): ProviderUser {
        $providerUser = new ProviderUser;

        $providerUser->map($attributes);
        $providerUser->setRaw($attributes);

        $providerUser->token = $token;
        $providerUser->refreshToken = $refreshToken;

        return $providerUser;
    }
}
