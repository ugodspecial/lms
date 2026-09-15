<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Administration\Services\AuditLogger;
use App\Domain\Identity\Enums\AuthProvider;
use App\Domain\Identity\Enums\ConnectedAccountStatus;
use App\Domain\Identity\Enums\ConnectedProvider;
use App\Domain\Identity\Enums\ConnectedPurpose;
use App\Domain\Identity\Enums\UserCreatedBy;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\ConnectedAccount;
use App\Domain\Identity\Models\User;
use App\Exceptions\PlatformException;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as ProviderUser;

/**
 * Linking an external identity to a platform account, and unlinking it (docs/07 W5).
 *
 * The provider handshake proves that somebody controls an account at Google or
 * Microsoft. It does not prove which account HERE it belongs to, and every rule in
 * this class is about closing that gap:
 *
 *   • one external identity belongs to one user, so a second account cannot claim
 *     a Google identity that is already in use — that claim is account takeover
 *     with a consent screen in front of it;
 *   • a provider that explicitly says the address is unverified is refused,
 *     because linking on an unverified address is how two people end up behind one
 *     login;
 *   • tokens are stored through the model's `encrypted` cast and are never written
 *     to the audit log, so neither a database dump nor an audit viewer yields
 *     somebody's Google session (§58).
 *
 * `ProviderUser` is Socialite's concrete `Two\User` rather than its `Contracts\User`
 * interface. The interface does not declare the token accessors — `getToken()`,
 * `getRefreshToken()`, `getExpiry()` — and static analysis is configured to check
 * method calls, so the concrete class is the honest hint: every provider Socialite
 * ships for the two supported here returns exactly that class.
 *
 * Socialite is installed but deliberately not auto-discovered (composer.json,
 * `dont-discover`): the platform registers its own providers in Phase 1's UI slice
 * so that an unconfigured provider renders no button at all (docs/07 W5, §93).
 * Nothing in this class resolves Socialite from the container, which is why it
 * works — and is testable — before that registration exists.
 */
final class OAuthLinkingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Store or refresh one link between a user and a provider, for one purpose.
     *
     * An upsert on (user_id, provider, purpose), which is the table's unique key:
     * signing in again refreshes the tokens on the row that already exists rather
     * than adding a second link to the same identity. Duplicate rows here would
     * mean two answers to "which token do we use for this person's calendar".
     *
     * `$scopes` is what the provider GRANTED, space-delimited, or null when it did
     * not say: recording the scopes that were asked for would claim something
     * nobody observed. `$expiresAt` is when the access token dies as the provider
     * reported it; null means no expiry is recorded, and a link with no recorded
     * expiry is treated as usable until a call fails — which is what the token
     * refresher in the integration layer is for (docs/07 W6).
     *
     * @throws PlatformException 422 `identity.provider_identity_missing`, no subject id
     * @throws PlatformException 422 `identity.provider_email_unverified`, provider says so
     * @throws PlatformException 409 `identity.provider_already_linked`, another user holds it
     */
    public function link(
        User $user,
        ProviderUser $providerUser,
        ConnectedProvider $provider,
        ConnectedPurpose $purpose = ConnectedPurpose::Login,
        ?string $scopes = null,
        ?DateTimeInterface $expiresAt = null,
        ?User $actor = null,
    ): ConnectedAccount {
        $providerUserId = trim((string) $providerUser->getId());

        if ($providerUserId === '') {
            // No subject identifier means there is nothing to link to: the row
            // would match every future sign-in from that provider, or none.
            throw new PlatformException(
                message: 'The provider did not return an account identifier, so nothing could be linked.',
                errorCode: 'identity.provider_identity_missing',
                statusCode: 422,
            );
        }

        $email = self::normaliseEmail($providerUser->getEmail());

        self::assertEmailIsNotReportedUnverified($providerUser, $provider);
        self::assertIdentityIsNotClaimed($user, $provider, $providerUserId);

        return DB::transaction(function () use (
            $user, $providerUser, $provider, $purpose, $providerUserId, $email, $scopes, $expiresAt, $actor
        ): ConnectedAccount {
            $account = ConnectedAccount::query()
                ->where('user_id', $user->getKey())
                ->where('provider', $provider->value)
                ->where('purpose', $purpose->value)
                ->first() ?? new ConnectedAccount;

            $isNew = ! $account->exists;
            $previousStatus = $isNew ? null : $account->status->value;

            // ConnectedAccount is wholly non-mass-assignable, so each value is
            // written out: the columns here are the assertions the access decision
            // is built on later.
            $account->user_id = $user->getKey();
            $account->provider = $provider;
            $account->purpose = $purpose;
            $account->provider_user_id = $providerUserId;
            $account->provider_email = $email;
            // The tokens are read as properties rather than through accessors:
            // `token` and `refreshToken` are the shape Socialite itself documents
            // for a resolved provider user, and neither is part of the
            // `Contracts\User` interface, so a getter here would be a guess about a
            // third-party class. `is_string` because a provider that returned no
            // token leaves the property null, and null is a legitimate value for
            // both columns.
            $token = $providerUser->token;
            $refreshToken = $providerUser->refreshToken;

            $account->access_token = is_string($token) && $token !== '' ? $token : null;
            $account->refresh_token = is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null;
            $account->expires_at = $expiresAt === null ? null : Carbon::instance($expiresAt);
            $account->scopes = $scopes;
            $account->meta = self::metaOf($providerUser);
            $account->status = ConnectedAccountStatus::Connected;
            $account->last_used_at = now();
            $account->save();

            if ($purpose === ConnectedPurpose::Login) {
                // W5 step 5: the account records how the person signs in. Only the
                // login purpose says anything about that — a meetings link is a
                // capability, not a credential.
                $authProvider = self::authProviderFor($provider);

                if ($authProvider !== null && $user->auth_provider !== $authProvider) {
                    $user->auth_provider = $authProvider;
                    $user->save();
                }
            }

            // Never the tokens. The scrubber would blank keys named access_token or
            // refresh_token, and that is a second line of defence rather than the
            // first: the values are not passed at all.
            $this->audit->record(
                event: 'auth.oauth_linked',
                subject: $account,
                oldValues: $isNew ? [] : ['status' => $previousStatus],
                newValues: [
                    'provider' => $provider->value,
                    'purpose' => $purpose->value,
                    'provider_email' => $email,
                    'status' => ConnectedAccountStatus::Connected->value,
                    'scopes' => $scopes,
                    'expires_at' => $account->expires_at?->toIso8601String(),
                ],
                tags: ['identity', 'oauth', $provider->value],
                actor: $actor,
            );

            return $account;
        });
    }

    /**
     * Resolve a provider sign-in to a platform account, creating one if needed
     * (docs/07 W5 step 4).
     *
     * The order of the three lookups is the security argument. An existing link
     * wins first, because the identity was already claimed and re-checked at that
     * time; an address match comes second, which is what lets a person who
     * registered with a password add Google later without ending up with two
     * accounts; creation is last, and only for an address nobody holds.
     *
     * @throws PlatformException 422 `identity.provider_email_missing`, no address shared
     * @throws PlatformException 409 `identity.email_held_by_closed_account`, address taken
     */
    public function findOrCreateForLogin(ConnectedProvider $provider, ProviderUser $providerUser): User
    {
        $providerUserId = trim((string) $providerUser->getId());

        if ($providerUserId !== '') {
            $existing = ConnectedAccount::query()
                ->where('provider', $provider->value)
                ->where('provider_user_id', $providerUserId)
                ->where('purpose', ConnectedPurpose::Login->value)
                ->where('status', '!=', ConnectedAccountStatus::Revoked->value)
                ->first();

            $owner = $existing?->user;

            if ($owner !== null) {
                // Refresh the tokens on the way in: a link that is used for sign-in
                // and never refreshed expires, and the person is then locked out of
                // the only credential they have.
                $this->link($owner, $providerUser, $provider, ConnectedPurpose::Login);

                return $owner;
            }
        }

        $email = self::normaliseEmail($providerUser->getEmail());

        if ($email === null) {
            throw new PlatformException(
                message: 'The provider did not share an email address, so there is nothing to match an account on.',
                errorCode: 'identity.provider_email_missing',
                statusCode: 422,
                errors: ['email' => ['Sign in with a password, or grant the provider permission to share your address.']],
            );
        }

        $matched = User::query()->where('email', $email)->first();

        if ($matched !== null) {
            $this->link($matched, $providerUser, $provider, ConnectedPurpose::Login);

            return $matched;
        }

        // Soft-deleted rows still hold the address, and `users.email` is unique —
        // including trashed rows. Creating a second account would fail on the index
        // with a raw QueryException, so the conflict is reported as what it is.
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw new PlatformException(
                message: 'That address belongs to an account which has been closed.',
                errorCode: 'identity.email_held_by_closed_account',
                statusCode: 409,
                errors: ['email' => ['Support has to reopen or release the address before it can be used to sign in again.']],
            );
        }

        return $this->register($provider, $providerUser, $email);
    }

    /**
     * Create an account from a verified provider identity.
     *
     * The account is active only when the provider asserts the address is verified.
     * When it does not — or says nothing — the account is created `pending` and sent
     * this platform's own verification email, which is W5's "fall back to a
     * verification email before linking". Asymmetry with `link()` is deliberate:
     * linking to an account that already exists means we already have our own
     * verification relationship with that address, while creating one from an
     * unverified claim would mint an identity on somebody else's word.
     */
    private function register(ConnectedProvider $provider, ProviderUser $providerUser, string $email): User
    {
        $verified = self::emailVerifiedClaim($providerUser) === true;

        $user = DB::transaction(function () use ($provider, $providerUser, $email, $verified): User {
            $user = new User;

            // name is NOT NULL and the provider may not have sent one. The local
            // part of the address is the least invented thing available; the
            // profile screen is where the person corrects it.
            $user->name = self::displayName($providerUser, $email);
            $user->email = $email;
            $user->password = null;
            $user->status = $verified ? UserStatus::Active : UserStatus::Pending;
            $user->email_verified_at = $verified ? now() : null;
            $user->auth_provider = self::authProviderFor($provider) ?? AuthProvider::Password;
            $user->created_by_type = UserCreatedBy::ByOAuth;
            $user->save();

            // No role. A person is not a student, parent, tutor or evaluator until a
            // profile says so (§6), and guessing one here would hand a stranger
            // portal access on the strength of an email address.

            $this->audit->record(
                event: 'auth.oauth_registered',
                subject: $user,
                newValues: [
                    'email' => $email,
                    'provider' => $provider->value,
                    'status' => $user->status->value,
                    'created_by_type' => UserCreatedBy::ByOAuth->value,
                    'email_verified_by_provider' => $verified,
                ],
                tags: ['identity', 'oauth', $provider->value],
                actor: $user,
            );

            $this->link($user, $providerUser, $provider, ConnectedPurpose::Login, actor: $user);

            return $user;
        });

        if (! $verified) {
            // A real notification, not a flag: the account exists, cannot reach a
            // portal, and the only way forward is a message that has to arrive.
            //
            // Sent after the commit rather than inside it. Inside, a rollback would
            // leave a person holding a verification link for an account that does
            // not exist, and the mail transport's latency would be spent holding a
            // transaction lock on `users` — on shared hosting, where the database is
            // the queue and the mailer is often SMTP, that is not a small cost.
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }

    /**
     * Stop holding a provider identity.
     *
     * Tokens are cleared rather than left in place with a `revoked` status. The
     * status is what the platform consults; the tokens are what an attacker with a
     * database dump consults, and a revoked row that still holds a working refresh
     * token is a revocation in name only.
     *
     * Calling the provider's own revocation endpoint is the integration layer's job
     * and needs the client credentials that arrive in Phase 7 (docs/07 W6). What is
     * guaranteed here is that this platform stops holding anything usable.
     *
     * @throws PlatformException 403 `identity.connected_account_not_yours`
     * @throws PlatformException 409 `identity.last_login_method`
     */
    public function disconnect(ConnectedAccount $account, ?User $actor = null): ConnectedAccount
    {
        $owner = $account->user;

        if ($owner === null) {
            // The column is NOT NULL with RESTRICT, so this cannot happen through
            // the application; it is here because a service that assumes its own
            // invariants are unbreakable is a service that cannot report it when
            // they are.
            throw new PlatformException(
                message: 'That connection has no account attached to it.',
                errorCode: 'identity.connected_account_orphaned',
                statusCode: 409,
            );
        }

        if ($actor !== null && ! $actor->is($owner) && ! $actor->can('users.update')) {
            throw new PlatformException(
                message: 'That connection belongs to somebody else.',
                errorCode: 'identity.connected_account_not_yours',
                statusCode: 403,
            );
        }

        if ($account->purpose === ConnectedPurpose::Login) {
            self::assertNotTheLastWayIn($owner, $account);
        }

        $previousStatus = $account->status->value;
        $hadRefreshToken = $account->refresh_token !== null && $account->refresh_token !== '';

        return DB::transaction(function () use ($account, $owner, $actor, $previousStatus, $hadRefreshToken): ConnectedAccount {
            $account->status = ConnectedAccountStatus::Revoked;
            $account->access_token = null;
            $account->refresh_token = null;
            $account->save();

            $this->audit->record(
                event: 'auth.oauth_unlinked',
                subject: $account,
                oldValues: [
                    'status' => $previousStatus,
                    'had_refresh_token' => $hadRefreshToken,
                ],
                newValues: [
                    'status' => ConnectedAccountStatus::Revoked->value,
                    'provider' => $account->provider->value,
                    'purpose' => $account->purpose->value,
                    'tokens_cleared' => true,
                ],
                tags: ['identity', 'oauth', $account->provider->value],
                actor: $actor ?? $owner,
            );

            return $account;
        });
    }

    /**
     * A person must not be able to disconnect their only way in.
     *
     * Not in the plan as a rule, and included because the alternative is a locked
     * account that support cannot reach either: with no password and no remaining
     * login link there is no way to sign in to change anything, so the only fix is
     * a database edit — an unrecorded manual change to an identity row.
     */
    private static function assertNotTheLastWayIn(User $owner, ConnectedAccount $account): void
    {
        if ($owner->hasPassword()) {
            return;
        }

        $otherLoginLinks = ConnectedAccount::query()
            ->where('user_id', $owner->getKey())
            ->where('purpose', ConnectedPurpose::Login->value)
            ->where('status', '!=', ConnectedAccountStatus::Revoked->value)
            ->whereKeyNot($account->getKey())
            ->count();

        if ($otherLoginLinks > 0) {
            return;
        }

        throw new PlatformException(
            message: 'That is the only way this account can sign in.',
            errorCode: 'identity.last_login_method',
            statusCode: 409,
            errors: ['connection' => ['Set a password first, or link another provider. Disconnecting this would leave the account unreachable, including to support.']],
        );
    }

    /**
     * One external identity, one user.
     *
     * Revoked rows do not count as a claim. Re-linking requires the person to
     * complete the provider's consent again, and that handshake — not the row — is
     * the proof of control. A revoked row is history.
     *
     * The refusal does not name the account that holds the identity: which account
     * a Google identity belongs to is not something a stranger at a consent screen
     * should be able to learn.
     */
    private static function assertIdentityIsNotClaimed(User $user, ConnectedProvider $provider, string $providerUserId): void
    {
        $heldBySomebodyElse = ConnectedAccount::query()
            ->where('provider', $provider->value)
            ->where('provider_user_id', $providerUserId)
            ->where('status', '!=', ConnectedAccountStatus::Revoked->value)
            ->where('user_id', '!=', $user->getKey())
            ->exists();

        if ($heldBySomebodyElse) {
            throw new PlatformException(
                message: sprintf('That %s account is already linked to a different profile here.', $provider->label()),
                errorCode: 'identity.provider_already_linked',
                statusCode: 409,
                errors: ['provider' => ['If that is your profile, sign in to it. If it is not, contact support — do not create a second account.']],
                context: ['provider' => $provider->value],
            );
        }
    }

    /**
     * Refuse an address the provider explicitly reports as unverified (W5).
     *
     * An absent claim is NOT treated as false. Google sends `email_verified`;
     * Microsoft's Graph user object does not, and refusing on "the provider said
     * nothing" would make a configured provider unusable — the failure §93 exists
     * to prevent. What is recorded instead is that the claim was absent, in `meta`,
     * so the decision is visible in the row rather than assumed later.
     */
    private static function assertEmailIsNotReportedUnverified(ProviderUser $providerUser, ConnectedProvider $provider): void
    {
        if (self::emailVerifiedClaim($providerUser) === false) {
            throw new PlatformException(
                message: sprintf('Your %s account reports that email address as unverified.', $provider->label()),
                errorCode: 'identity.provider_email_unverified',
                statusCode: 422,
                errors: ['email' => ['Verify the address with the provider, then connect again.']],
                context: ['provider' => $provider->value],
            );
        }
    }

    /**
     * What the provider said about the address, if it said anything.
     */
    private static function emailVerifiedClaim(ProviderUser $providerUser): ?bool
    {
        $raw = (array) $providerUser->getRaw();

        foreach (['email_verified', 'emailVerified', 'verified'] as $claim) {
            if (! array_key_exists($claim, $raw)) {
                continue;
            }

            $value = $raw[$claim];

            if (is_bool($value)) {
                return $value;
            }

            // Providers are inconsistent here: Google sends a boolean, some OIDC
            // implementations send the string "true".
            if (is_string($value)) {
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
        }

        return null;
    }

    private static function normaliseEmail(mixed $email): ?string
    {
        // Typed mixed rather than ?string on purpose: the provider object is a
        // third-party boundary, and an address that arrives as anything else is
        // "no address", not a TypeError on a login callback.
        if (! is_string($email)) {
            return null;
        }

        $email = Str::lower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * What the provider returned that is worth keeping, with the tokens left out.
     *
     * @return array<string, mixed>|null
     */
    private static function metaOf(ProviderUser $providerUser): ?array
    {
        $raw = (array) $providerUser->getRaw();

        if ($raw === []) {
            return null;
        }

        // The claim that decided whether the address was trusted is the one part of
        // the raw payload worth keeping: it is the evidence for a decision made
        // once, at link time, that an investigation may need to revisit.
        $meta = ['email_verified_claim' => self::emailVerifiedClaim($providerUser)];

        if (isset($raw['hd']) && is_string($raw['hd'])) {
            // Google's hosted domain, when the account is a Workspace one. Which
            // organization a person signed in from is useful to an administrator and
            // is not a credential.
            $meta['hosted_domain'] = $raw['hd'];
        }

        return $meta;
    }

    private static function displayName(ProviderUser $providerUser, string $email): string
    {
        foreach ([$providerUser->getName(), $providerUser->getNickname()] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return (string) Str::of($email)->before('@')->replace(['.', '_', '-'], ' ')->trim();
    }

    private static function authProviderFor(ConnectedProvider $provider): ?AuthProvider
    {
        return match ($provider) {
            ConnectedProvider::Google => AuthProvider::Google,
            ConnectedProvider::Microsoft => AuthProvider::Microsoft,

            // Zoom and Google-as-meetings are capabilities, never credentials. A
            // meetings link that also changed how the person signs in would be a
            // scope escalation nobody consented to.
            ConnectedProvider::Zoom => null,
        };
    }
}
