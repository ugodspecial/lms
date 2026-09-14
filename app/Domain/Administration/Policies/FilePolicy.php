<?php

declare(strict_types=1);

namespace App\Domain\Administration\Policies;

use App\Domain\Administration\Enums\FileVisibility;
use App\Domain\Administration\Models\File;
use App\Domain\Administration\ScopedGrants;
use App\Domain\Identity\Models\User;

/**
 * Who may read a stored file (§40, §57, §58, §59, ADR-06, ADR-10).
 *
 * The four visibility tiers are four different questions, and this is the only
 * place they are answered:
 *
 *   public         → anybody, including a guest. This is the only tier whose bytes
 *                    a web server may serve directly, and the only one whose disk
 *                    is symlinked into the document root.
 *   authenticated  → anybody signed in. Course material: usable without a per-file
 *                    grant, and still not indexable by the world.
 *   private        → the person who put it there, the person it belongs to, or an
 *                    administrator. A tutor's CV, an invoice, a certificate PDF.
 *   restricted     → an explicit decision, per request. A paid product file, a
 *                    minor's document, graded work.
 *
 * The first parameter of the read abilities is nullable on purpose. Laravel calls
 * a policy for a guest only when the method accepts one, and a guest has to be able
 * to reach the public tier — otherwise the tier would be a lie and every public
 * asset would need an account to download it.
 *
 * `restricted` fails closed. The person a file belongs to, and the person who put
 * it there, always reach it — a student who cannot download their own submission
 * would be the first thing broken, and the first "fix" would be to hand out the
 * staff permission. Everybody else needs `files.view_restricted`, which docs/05
 * §3.9 grants to Super Admin and Administrator outright and to Academic Admin and
 * Finance Officer *scoped*. Until a domain says which records a scoped holder
 * covers — Phase 2 for student documents through the guardian link, Phase 8 for
 * purchased products through the entitlement — a scoped holder reaches nothing
 * beyond their own. The widening has to be a rule somebody wrote and reviewed,
 * never an absence of one.
 *
 * Ownership is read off the morph columns rather than by loading `fileable`, so a
 * file owned by a model from a module that is not installed yet cannot break the
 * check that decides whether somebody else may read it.
 */
final class FilePolicy
{
    /**
     * Whether the registry may be browsed at all.
     *
     * `files.view_restricted` is the staff-facing permission: the registry lists
     * names, sizes, owners and checksums for every file on the platform, which is a
     * supervision tool rather than something every account that may upload should
     * be able to page through.
     */
    public function viewAny(?User $user): bool
    {
        return $user !== null && $user->can('files.view_restricted');
    }

    /**
     * The registry row: name, size, owner, checksum.
     *
     * Answered by the same rule as the bytes, because for a restricted file knowing
     * that it exists is already the sensitive part — a list of document names from
     * a minor's application tells a reader what the platform holds about them.
     */
    public function view(?User $user, File $file): bool
    {
        return $this->download($user, $file);
    }

    public function download(?User $user, File $file): bool
    {
        return match ($file->visibility) {
            FileVisibility::IsPublic => true,

            FileVisibility::IsAuthenticated => $user !== null,

            FileVisibility::IsPrivate => $user !== null && (
                $this->isConnectedTo($user, $file)
                || ScopedGrants::holdsUnscoped($user, 'files.view_restricted')
            ),

            FileVisibility::IsRestricted => $user !== null && $this->mayViewRestricted($user, $file),
        };
    }

    /**
     * Whether this account may register a new file.
     *
     * `files.upload` is held by every participant role — a student submits work, a
     * parent attaches proof of address, a tutor uploads a CV — so this is the
     * coarse gate. What they may upload, and under which visibility, is decided by
     * FileCategory::minimumVisibility() and by the domain feature doing the
     * uploading, not here.
     */
    public function create(User $user): bool
    {
        return $user->can('files.upload');
    }

    public function delete(User $user, File $file): bool
    {
        if (! $user->can('files.delete')) {
            return false;
        }

        // "own uploads" is the qualifier docs/05 §3.9 records for every role except
        // Super Admin and Administrator. When a file may be WITHDRAWN is a rule of
        // the domain that owns it — an assignment submission after its deadline, an
        // invoice after it was issued — and arrives with that domain; the registry
        // answers who, not when.
        if (ScopedGrants::holdsUnscoped($user, 'files.delete')) {
            return true;
        }

        return $this->isConnectedTo($user, $file);
    }

    public function restore(User $user, File $file): bool
    {
        // Undoing a delete is the same decision as making one, and a stricter rule
        // here would let a file be removed by somebody who could not put it back.
        return $this->delete($user, $file);
    }

    /**
     * A file's assertions are not edited.
     *
     * The Super Admin bypass in AuthServiceProvider answers `true` before this is
     * reached, so `can('update', $file)` is true for that one role. What actually
     * prevents a reclassification is that nothing performs one: there is no update
     * route, no update service, and no mass assignment on the model. The policy's
     * `false` is the answer for every role the bypass does not cover, and it is
     * what a component or a form request consults.
     *
     * Reclassifying a file — a draft made public, a public asset withdrawn — changes
     * what the access decision was built on, so it goes through FileService with the
     * category's floor re-checked and an audit entry recording both states. A form
     * that could flip `visibility` directly would be the whole protection undone in
     * one request, which is also why nothing on File is mass assignable.
     */
    public function update(User $user, File $file): false
    {
        return false;
    }

    /**
     * Erasure is a retention process, not a button.
     *
     * Removing the bytes has to decide what happens to the record that refers to
     * them — an invoice on a paid order, a submission on a graded assessment — and
     * in that order (§58). A `forceDelete` here would be a way to destroy evidence
     * that leaves the trail saying nothing about why.
     */
    public function forceDelete(User $user, File $file): false
    {
        return false;
    }

    private function mayViewRestricted(User $user, File $file): bool
    {
        // Their own first, and before any permission is consulted. A student's own
        // submission and a tutor's own graded work are the normal cases for this
        // tier: if reaching a restricted file required a staff permission, the tier
        // would be unusable by the people it exists to protect, and the first
        // "fix" would be to grant everybody the permission.
        if ($this->isConnectedTo($user, $file)) {
            return true;
        }

        if (! $user->can('files.view_restricted')) {
            return false;
        }

        if (ScopedGrants::holdsUnscoped($user, 'files.view_restricted')) {
            return true;
        }

        // docs/05 §3.9 qualifies this permission for Academic Admin and Finance
        // Officer as "scoped", and Phase 1 has no scope to apply: which records a
        // Finance Officer supervises is a question about invoices and orders
        // (Phase 8), and which records an Academic Admin supervises is a question
        // about the guardian link (Phase 2). So a scoped holder reaches nothing
        // beyond what is already theirs above. That makes the grant inert today,
        // which is deliberate and visible — `FilePolicyTest` pins it — because the
        // alternative is guessing a scope in a policy and having the matrix and the
        // code disagree in the direction that leaks a minor's record.
        return false;
    }

    /** Whether this user put the file there, or the file belongs to them. */
    private function isConnectedTo(User $user, File $file): bool
    {
        if ($file->uploaded_by !== null && (int) $file->uploaded_by === (int) $user->getKey()) {
            return true;
        }

        return $file->fileable_type === User::class
            && $file->fileable_id !== null
            && (int) $file->fileable_id === (int) $user->getKey();
    }
}
