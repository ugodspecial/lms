<?php

declare(strict_types=1);

namespace App\Domain\Administration\Policies;

use App\Domain\Administration\Models\AuditLog;
use App\Domain\Identity\Models\User;

/**
 * Who may read the audit trail, and nobody at all may write to it (§56, ADR-12).
 *
 * `audit.view` is granted to Super Admin and Administrator only (docs/05 §3.9).
 * That is narrower than the settings and student permissions around it, and the
 * reason is what the trail contains: before-and-after values from every domain,
 * including the ones an Academic Admin has no business seeing — payment amounts,
 * another department's student records, who suspended whom. Redaction keeps
 * secrets out of it (SensitiveDataScrubber), but a redacted trail is still a
 * record of everything everybody did, and "two roles can read it" is a decision
 * rather than an oversight.
 *
 * The write abilities return false for every principal that reaches this class.
 * Three layers make an audit entry uneditable, and they are redundant on
 * purpose, because each one survives the failure of the others:
 *
 *   1. no route — docs/06 records that the audit viewer has no edit or delete
 *      route at all, so there is nothing for a browser to call;
 *   2. this policy — denies the ability even if a route is added later by
 *      somebody who did not read the first line;
 *   3. the model — AuditLog::performUpdate() and delete() throw, which also
 *      covers `saveQuietly()`, an event listener, tinker and a seeder.
 *
 * Retention pruning stays possible and is not an edit: it deletes an old RANGE
 * through a bulk query-builder delete, which never instantiates a model and so
 * never reaches layer 3. Removing history in bulk on a schedule is a retention
 * policy; removing one record is tampering, and only the first is permitted.
 *
 * Super Admin bypasses this class through `Gate::before`, which is why the
 * `false` return type below is honest rather than merely hopeful: it is the
 * answer for every principal that gets as far as a policy.
 */
final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        // No per-entry scoping: an administrator who may read the trail may read
        // it. A partial trail is worse than a narrow audience, because the gap is
        // invisible — there is no way to tell from inside it what is missing.
        return $user->can('audit.view');
    }

    public function create(User $user): false
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): false
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): false
    {
        return false;
    }

    public function restore(User $user, AuditLog $auditLog): false
    {
        return false;
    }

    public function forceDelete(User $user, AuditLog $auditLog): false
    {
        return false;
    }
}
