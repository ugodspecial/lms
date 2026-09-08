<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\ConsentType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One consent decision, recorded once and never edited (§58, §9).
 *
 * This table is append-only and the model enforces it: `save()` on an existing
 * row throws, and so does `delete()`. Withdrawing consent inserts a `granted =
 * false` row; it does not update the row that granted it.
 *
 * That is not fastidiousness. A consent record exists to answer "did this person
 * agree to this, at this time, from this address, under this version of the
 * text?" An editable row cannot answer it, because the answer would depend on
 * when you asked — and the edit that changes `granted` to false is
 * indistinguishable from the record having been false all along. For a platform
 * holding children's data, a consent history that can be rewritten is worth
 * nothing as evidence.
 *
 * Deletion is blocked for the same reason. Erasure under §58 is a deliberate,
 * audited process that has to decide what happens to the evidence that erasure
 * was requested; it is not a `delete()` call from a controller, and it must not
 * become one by accident.
 *
 * `student_id` carries no foreign key yet: `students` arrives in Phase 2, which
 * adds the constraint and the relation. A parent's consent on behalf of a child
 * is recorded here with that column set.
 *
 * @property Carbon|null $recorded_at
 * @property ConsentType $type
 * @property bool $granted
 */
#[Guarded(['*'])]
class Consent extends Model
{
    /** There is no `updated_at` column and there must never be one. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'granted' => 'boolean',
            'recorded_at' => 'datetime',
            'user_id' => 'integer',
            'student_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Consent>  $query
     * @return Builder<Consent>
     */
    public function scopeGranted(Builder $query): Builder
    {
        return $query->where('granted', true);
    }

    /**
     * @param  Builder<Consent>  $query
     * @return Builder<Consent>
     */
    public function scopeOfType(Builder $query, ConsentType $type): Builder
    {
        return $query->where('type', $type);
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
            'Consent records are append-only. Withdrawing consent means inserting a new row with granted = false, '.
            'not editing the row that granted it — an editable consent record cannot prove anything.'
        );
    }

    /**
     * @throws LogicException
     */
    public function delete(): ?bool
    {
        throw new LogicException(
            'Consent records are evidence and cannot be deleted through the model. '.
            'Erasure under §58 is an explicit, audited process that has to decide what happens to the record of the request itself.'
        );
    }
}
