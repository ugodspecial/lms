<?php

declare(strict_types=1);

namespace App\Domain\Administration\Models;

use App\Domain\Administration\Enums\FileCategory;
use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Identity\Models\User;
use App\Support\Models\GeneratesUuid;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One stored object and the record of who may read it (ADR-06, §58).
 *
 * Soft deleted, because a file can be evidence: an assignment submission, an
 * invoice, a signed certificate. Removing the row while the record that refers to
 * it still exists would leave a dangling reference and no way to answer "was
 * anything here?". Erasure is a retention process that has to decide what to do
 * about both, in that order.
 *
 * Nothing on this model is mass assignable. A file row asserts a visibility, an
 * owning record, a checksum and a disk path, and those assertions are what the
 * access decision is built on; they are written by the file service after it has
 * checked the caller, not by whatever array a request happened to contain.
 *
 * `visibility` describes an INTENT, not a grant. A row marked public is still
 * served through the access path, and a row marked restricted is not made
 * readable by any property of this model — see the ADR-06 note in docs/11.
 *
 * @property Carbon|null $deleted_at
 * @property FileCategory $category
 * @property FileVisibility $visibility
 * @property string $uuid
 */
#[Guarded(['*'])]
class File extends Model
{
    use GeneratesUuid, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => FileCategory::class,
            'visibility' => FileVisibility::class,
            'size_bytes' => 'integer',
            'meta' => 'array',
            'fileable_id' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    /** The record that owns this file — a user, a student, a course, an order. */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Who put it here, which may not be who owns it. NULL once that person is removed. */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @param  Builder<File>  $query
     * @return Builder<File>
     */
    public function scopeOfCategory(Builder $query, FileCategory $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Whether this file was declared publicly readable.
     *
     * This is a property of the row, not a permission to serve it. The signed-URL
     * path still runs the access checks; a mislabelled row must not become a
     * readable file just because this method said so.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->visibility === FileVisibility::IsPublic;
    }
}
