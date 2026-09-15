<?php

declare(strict_types=1);

namespace App\Domain\Communication\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's choice about one notification on one channel (§82).
 *
 * The row exists only to record a choice that differs from the default. Every
 * notification defaults to mail + database on, so a user with no rows here has
 * every notification enabled; a missing row is never an error state and must not
 * be treated as an opt-out.
 *
 * `notification_key` matches a key in the code registry of notifications, exactly
 * as `permissions.name` matches the Permissions registry: code decides what
 * exists, the database records what this user chose. That is why the unique key
 * is (user_id, notification_key) and not user_id alone — one row per user cannot
 * express a different choice per notification, which is the entire purpose of the
 * table. Where docs/03's ERD draws this as one-to-one with `users`, docs/04 §8 is
 * followed instead; the deviation is recorded in docs/10.
 *
 * The fillable list omits `is_transactional` on purpose. That flag says whether
 * the platform permits an opt-out at all — a payment receipt, a safeguarding
 * alert, a session cancellation are not negotiable — so it is a property of the
 * notification, written when the row is first created. Letting a request carry it
 * would let someone mark a transactional notification as optional for themselves,
 * which is the rule §82 exists to prevent. The server-side refusal lives in
 * NotificationPreferenceService; the disabled toggle in the UI is only its
 * reflection.
 *
 * `user_id` cascades on delete, unlike every other reference to `users` in the
 * schema, which restricts. A preference has no academic or evidential value once
 * the person is gone, and keeping it would retain data about an erased user for
 * no reason (§58, docs/04 §66.10).
 *
 * @property bool $channel_mail
 * @property bool $channel_database
 * @property bool $is_transactional
 */
#[Fillable(['notification_key', 'channel_mail', 'channel_database'])]
class NotificationPreference extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel_mail' => 'boolean',
            'channel_database' => 'boolean',
            'is_transactional' => 'boolean',
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<NotificationPreference>  $query
     * @return Builder<NotificationPreference>
     */
    public function scopeForKey(Builder $query, string $notificationKey): Builder
    {
        return $query->where('notification_key', $notificationKey);
    }

    /**
     * Whether this notification may be turned off at all.
     *
     * The UI reads this to render the toggle as disabled, and the service reads it
     * to refuse the change. Reading the flag is not the enforcement — a request
     * that ignores the disabled attribute still has to be refused server-side,
     * because §82's rule cannot depend on a browser honouring markup.
     */
    public function allowsOptOut(): bool
    {
        return ! $this->is_transactional;
    }
}
