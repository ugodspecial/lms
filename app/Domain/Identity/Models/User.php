<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Models\File;
use App\Domain\Communication\Models\NotificationPreference;
use App\Domain\Identity\Enums\AuthProvider;
use App\Domain\Identity\Enums\UserCreatedBy;
use App\Domain\Identity\Enums\UserStatus;
use App\Support\Models\GeneratesUuid;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * The platform's one identity record (§6, docs/02 §1, ADR-02).
 *
 * Student, parent, tutor and evaluator are PROFILE tables that may point at a
 * user — they are not kinds of user. A person who is both a parent and a tutor is
 * one row here and two profiles, which is why this table has no `role` column and
 * why nothing downstream may branch on "what kind of user" this is.
 *
 * Two decisions here are deliberate and easy to undo by accident:
 *
 * `implements MustVerifyEmail` IS THE SWITCH, not a restatement. Laravel 13's
 * base user class uses the MustVerifyEmail TRAIT — so the methods exist on every
 * user model — but deliberately does not declare the CONTRACT. The `verified`
 * middleware and the verification-notice redirect both test
 * `$user instanceof MustVerifyEmail`, so a model that omits this line has every
 * verification method and none of the enforcement: accounts would sit forever
 * unverified and nothing would ever ask them to verify.
 *
 * SOFT DELETE ONLY. Academic and financial records reference `users` with
 * ON DELETE RESTRICT, so the correct end state for a person who leaves is
 * `status = deactivated` plus `deleted_at`. A hard delete would either fail on
 * the foreign key or, if someone "fixed" that, orphan a child's academic history.
 * Erasure is a separate, explicit process (§58), not a `delete()` call.
 *
 * `#[UseFactory]` IS REQUIRED, NOT DECORATIVE. The framework's default factory
 * guesser strips a leading `App\Models\` and maps what remains onto
 * `Database\Factories\`, so a model in a domain namespace resolves to
 * `Database\Factories\Domain\Identity\Models\UserFactory` — which does not
 * exist, and `User::factory()` fails with a class-not-found error rather than a
 * helpful message. Every domain model with a factory needs this attribute (or
 * `newFactory()`), and the reason is recorded here once rather than rediscovered
 * per model in Phase 2.
 *
 * FILLABLE IS NARROW ON PURPOSE. It covers what a person may change about
 * themselves through a profile form. Everything else — `status`, `auth_provider`,
 * `created_by_type`, the 2FA columns, `avatar_file_id`, the login trail — is
 * written by code that has already decided the caller may write it. A wider list
 * would let a crafted request set its own `status` to active or point
 * `avatar_file_id` at somebody else's private file.
 *
 * The `@property` block exists because Larastan does not read the `casts()`
 * method: without it a column cast to `datetime` is inferred as `string|null`,
 * and every `now()` assignment at a call site reads as a type error. Annotating
 * the model is the fix rather than assigning pre-formatted strings, which would
 * satisfy the analyser and quietly teach the wrong convention everywhere else.
 *
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $deleted_at
 * @property UserStatus $status
 * @property AuthProvider $auth_provider
 * @property UserCreatedBy $created_by_type
 * @property bool $two_factor_enabled
 * @property int|null $avatar_file_id
 * @property string $uuid
 */
#[UseFactory(UserFactory::class)]
#[Fillable(['name', 'email', 'password', 'timezone', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use GeneratesUuid, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        /*
         * `two_factor_secret` and `two_factor_recovery_codes` deliberately have NO
         * cast, even though both are encrypted at rest. Fortify owns this feature:
         * its TwoFactorAuthenticatable trait encrypts and decrypts those columns
         * itself. An `encrypted` cast here would write ciphertext over ciphertext
         * and hand Fortify something it cannot decrypt, and nothing would fail
         * until somebody tried to log in with a code.
         *
         * The requirement — a database dump must not be enough to bypass 2FA (§58)
         * — is still met, by Fortify. The migration that adds the columns records
         * the same decision so the two cannot drift apart.
         */
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'auth_provider' => AuthProvider::class,
            'created_by_type' => UserCreatedBy::class,
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'avatar_file_id' => 'integer',
            'last_login_at' => 'datetime',
        ];
    }

    // ── Relationships ───────────────────────────────────────────────────────

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(File::class, 'avatar_file_id');
    }

    /** Files this user owns through the polymorphic `fileable` relation. */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /** Files this user uploaded, which may include files owned by someone else. */
    public function uploads(): HasMany
    {
        return $this->hasMany(File::class, 'uploaded_by');
    }

    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /** Audit entries this user CAUSED. Not entries about this user. */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // ── Queries ─────────────────────────────────────────────────────────────

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Whether 2FA is usable, not merely switched on.
     *
     * `two_factor_enabled` is set when the user asks for it; the secret only
     * becomes real once they have proven they can read a code from it. Treating
     * the flag alone as "protected" is how an account ends up shown as secured
     * while a login still only needs a password.
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_enabled && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Whether this account signed in with a password at all.
     *
     * An OAuth-only account has a NULL password. That is not the same as an empty
     * one, and a password-check flow must not treat "no password" as "password
     * failed" — it should say there is nothing to reset, or the user is told their
     * account is broken when it is working as designed.
     */
    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }
}
