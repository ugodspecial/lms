<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\ConnectedAccountStatus;
use App\Domain\Identity\Enums\ConnectedProvider;
use App\Domain\Identity\Enums\ConnectedPurpose;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One link between a user and an external provider, for one purpose.
 *
 * The unique key is (user_id, provider, purpose) — NOT (user_id, provider). One
 * Google identity is linked once to sign a person in and once to provision Meet
 * rooms, and those two links have different scopes, different tokens, different
 * expiry and different consequences when revoked. Collapsing them into one row
 * would mean disconnecting Google Calendar also signed the person out, and would
 * make it impossible to hold a read-only login token alongside a meetings token
 * that can create events.
 *
 * Tokens are stored under an `encrypted` cast. A database dump must not be enough
 * to act as somebody's Google or Zoom account (§58); the app key is the only thing
 * standing between a leaked dump and a leaked calendar.
 *
 * `scopes` has no cast on purpose. OAuth scopes are a space-delimited string on
 * the wire and a list in our heads, and the two are not interchangeable: whichever
 * representation the integration layer settles on belongs there, not baked into a
 * persistence cast that every provider then has to satisfy.
 *
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property ConnectedProvider $provider
 * @property ConnectedPurpose $purpose
 * @property ConnectedAccountStatus $status
 */
#[Guarded(['*'])]
class ConnectedAccount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => ConnectedProvider::class,
            'purpose' => ConnectedPurpose::class,
            'status' => ConnectedAccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'meta' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the stored token can still be used, as far as this record knows.
     *
     * Expiry alone is not the answer: a revoked token is dead whatever its
     * timestamp says, and a connected token past `expires_at` may still be usable
     * if a refresh token is present. Deciding that is the integration layer's job;
     * this only reports the two facts the row actually holds.
     */
    public function isUsable(): bool
    {
        return $this->status === ConnectedAccountStatus::Connected
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function canRefresh(): bool
    {
        return $this->refresh_token !== null && $this->refresh_token !== '';
    }
}
