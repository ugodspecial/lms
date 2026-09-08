<?php

declare(strict_types=1);

namespace App\Domain\Administration\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in the platform's audit trail (§56).
 *
 * APPEND-ONLY, and enforced here rather than left as a convention. `save()` on an
 * existing row throws and so does `delete()`, because the only reason to edit an
 * audit entry is to change what history says happened. Note that this also blocks
 * `saveQuietly()` and `updateQuietly()`, which suppress model EVENTS — a guard
 * built on an `updating` listener would be silently switchable by anyone who
 * needed events off for an unrelated reason.
 *
 * What stays open is the retention pruner. Pruning deletes an old RANGE through a
 * bulk query-builder delete, which never instantiates a model and so never reaches
 * `delete()` here. That distinction is the whole design: removing history in bulk
 * on a schedule is a retention policy; removing one record is tampering, and only
 * the first is permitted.
 *
 * There is no `updated_at` column — `UPDATED_AT` is null so Eloquent never tries
 * to write one.
 *
 * `old_values` / `new_values` are expected to arrive already redacted by
 * SensitiveDataScrubber (a later Phase-1 slice). The scrubbing happens on write,
 * not on render: this table is readable by administrators, so a stored password
 * hash or 2FA secret would make the audit log the most valuable table in the
 * database, and hiding it in the UI would not change that (§77).
 *
 * @property Carbon|null $created_at
 */
#[Guarded(['*'])]
class AuditLog extends Model
{
    /** The table has no `updated_at` column and must never grow one. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
            'user_id' => 'integer',
            'auditable_id' => 'integer',
        ];
    }

    /** The actor. NULL for system events, which have no actor and are still worth recording. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The record acted upon, if the event has one. Polymorphic, with no foreign key. */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeOfEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    /**
     * Every event in a domain: `scopeOfEventPrefix($q, 'orders.')`.
     *
     * Event names are dotted deliberately, so a prefix match on the indexed
     * `event` column lists a whole domain's history without a second table.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeOfEventPrefix(Builder $query, string $prefix): Builder
    {
        return $query->where('event', 'like', $prefix.'%');
    }

    /**
     * The comma-separated `tags` column as a list.
     *
     * @return array<int, string>
     */
    public function tagList(): array
    {
        $tags = $this->tags;

        if (! is_string($tags) || $tags === '') {
            return [];
        }

        // trim then filter: a hand-written `security, finance` and a generated
        // `security,finance` both have to read the same, and a trailing comma must
        // not become an empty tag that matches every filter.
        return array_values(array_filter(array_map('trim', explode(',', $tags))));
    }

    /**
     * The generic must read `static`, exactly as the parent declares it. A
     * concrete `Builder<Model>` here is a narrower parameter type than the parent
     * accepts, which is an LSP violation the analyser reports and PHP does not.
     *
     * @param  Builder<static>  $query
     *
     * @throws LogicException
     */
    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException(
            'Audit entries are append-only. A correction is a new entry describing what happened, '.
            'not an edit to the entry that already describes it.'
        );
    }

    /**
     * @throws LogicException
     */
    public function delete(): ?bool
    {
        throw new LogicException(
            'An audit entry cannot be deleted through the model. Retention pruning removes an old range '.
            'with a bulk query-builder delete, which does not route through here; removing one record is tampering.'
        );
    }
}
