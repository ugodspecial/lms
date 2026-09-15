<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Administration\Services\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\UserStatusTransitions;
use App\Exceptions\PlatformException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only way an account's lifecycle state changes (docs/02 §1, docs/06 §2).
 *
 * Four named methods rather than one `transition($status)`, because each state
 * change carries rules that belong to it and to nothing else: activation follows
 * verification, deactivation may not be self-inflicted, and the two that end
 * access have to revoke the sessions that already exist. A single entry point
 * would gather all of those into one `match` and make every caller responsible for
 * knowing which of them applied.
 *
 * What is decided HERE and what is decided elsewhere is deliberate. Whether an
 * account MAY move is a domain rule and lives in `UserStatusTransitions`. Whether
 * the CALLER may move it is authorization — `users.suspend` and
 * `users.deactivate`, checked by the permission middleware and the policy layer
 * before this service is reached (§55) — because a service that re-checked the
 * caller's permissions would be a second, drifting copy of the authorization
 * matrix.
 *
 * Refusing a NEW sign-in for a suspended or deactivated account is the
 * authentication layer's job and arrives with the auth screens (docs/10 Phase 1,
 * UI row). What happens here is what makes an EXISTING sign-in stop working: the
 * session rows are deleted in the same transaction as the status change. Without
 * that, changing the column would change nothing at all for a browser that is
 * already signed in — the session row is what authenticates the next request, and
 * it stays valid until somebody removes it.
 */
final class UserStatusService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Move an account to active.
     *
     * Verification first, always. `pending` exists because a self-registered user
     * who has not proved they own the address must not appear in directories or be
     * granted portal access, and an activation that did not require
     * `email_verified_at` would be a way around that which nobody has to write
     * down anywhere to find.
     *
     * @throws PlatformException 422 `identity.email_not_verified` if the address has
     *                           not been verified
     */
    public function activate(User $user, string $reason, ?User $actor = null): User
    {
        if ($user->email_verified_at === null) {
            throw new PlatformException(
                message: 'That email address has not been verified, so the account cannot be activated.',
                errorCode: 'identity.email_not_verified',
                statusCode: 422,
                errors: ['email' => ['Activation follows verification. Send the verification email again, or verify the address, then activate.']],
            );
        }

        return $this->apply($user, UserStatus::Active, $reason, $actor);
    }

    /**
     * End an account's access without erasing it.
     *
     * @throws PlatformException 422 `identity.status_transition_not_allowed`
     */
    public function suspend(User $user, string $reason, ?User $actor = null): User
    {
        return $this->apply($user, UserStatus::Suspended, $reason, $actor);
    }

    /**
     * Restore a suspended account.
     *
     * The reason is required rather than defaulted. A trail that shows a suspension
     * lifted with no stated reason is indistinguishable from one that was edited,
     * which is the property an audit log exists to not have (§56).
     *
     * @throws PlatformException 422 `identity.status_transition_not_allowed`
     */
    public function reinstate(User $user, string $reason, ?User $actor = null): User
    {
        return $this->apply($user, UserStatus::Active, $reason, $actor);
    }

    /**
     * Close an account. The terminal state, and the replacement for deletion.
     *
     * Academic and financial rows reference `users` with ON DELETE RESTRICT
     * (docs/04 §0), so a hard delete either fails on the foreign key or — if
     * somebody "fixes" that — orphans a child's academic history. The correct end
     * state is `status = deactivated` plus `deleted_at`. Erasure under §58 is a
     * separate, explicit process with its own checks; this is not it.
     *
     * @throws PlatformException 422 `identity.self_deactivation` if the person doing
     *                           it is the account being closed
     * @throws PlatformException 422 `identity.status_transition_not_allowed`
     */
    public function deactivate(User $user, string $reason, ?User $actor = null): User
    {
        // docs/06 §2: "Cannot deactivate self". Not a UI nicety. An administrator
        // who closes their own account mid-session loses the ability to undo it,
        // and restoring them then needs a second Super Admin or a database edit —
        // which is how a support ticket turns into an unrecorded manual change.
        //
        // The actor is resolved here rather than only when one is passed, because
        // `Auth::user()` is who the audit entry will name anyway: checking the
        // explicit argument and not the implicit one would leave the common call
        // shape unguarded.
        $responsible = $actor ?? Auth::user();

        if ($responsible instanceof User && $responsible->is($user)) {
            throw new PlatformException(
                message: 'You cannot deactivate your own account.',
                errorCode: 'identity.self_deactivation',
                statusCode: 422,
                errors: ['account' => ['Ask another administrator to close this account.']],
            );
        }

        return $this->apply($user, UserStatus::Deactivated, $reason, $actor);
    }

    /**
     * Validate the move, then make it and everything that follows from it in one
     * transaction.
     *
     * @throws PlatformException 422 `identity.status_unchanged` or
     *                           `identity.status_transition_not_allowed`
     */
    private function apply(User $user, UserStatus $to, string $reason, ?User $actor): User
    {
        $from = $user->status;

        // Trimmed here rather than at each caller: a reason of only spaces is not a
        // reason, and every caller would otherwise have to remember to check.
        $reason = trim($reason);

        if ($reason === '') {
            throw new PlatformException(
                message: 'A status change needs a reason.',
                errorCode: 'identity.status_reason_required',
                statusCode: 422,
                errors: ['reason' => ['Record why the account is changing state; it is the only part of this an investigation can read later.']],
            );
        }

        if ($from === $to) {
            throw new PlatformException(
                message: sprintf('That account is already %s.', $to->label()),
                errorCode: 'identity.status_unchanged',
                statusCode: 422,
                errors: ['status' => [sprintf('The account is already %s, so there is nothing to change.', $to->label())]],
                context: ['status' => $to->value],
            );
        }

        if (! UserStatusTransitions::allows($from, $to)) {
            throw new PlatformException(
                message: sprintf('An account that is %s cannot become %s.', $from->label(), $to->label()),
                errorCode: 'identity.status_transition_not_allowed',
                statusCode: 422,
                errors: ['status' => [self::explainRefusal($from, $to)]],
                context: ['from' => $from->value, 'to' => $to->value],
            );
        }

        return DB::transaction(function () use ($user, $from, $to, $reason, $actor): User {
            $revoked = $this->endsAccess($to)
                ? DB::table('sessions')->where('user_id', $user->getKey())->delete()
                : 0;

            $user->status = $to;
            $user->save();

            if ($to === UserStatus::Deactivated) {
                // After the status write, so the row ends up as the pair docs/02
                // describes: deactivated AND soft-deleted. `delete()` on a
                // SoftDeletes model is an UPDATE of `deleted_at`, never a removal.
                $user->delete();
            }

            $this->audit->record(
                event: 'users.status_changed',
                subject: $user,
                oldValues: ['status' => $from->value],
                newValues: [
                    'status' => $to->value,
                    'reason' => $reason,
                    'sessions_revoked' => $revoked,
                    'soft_deleted' => $to === UserStatus::Deactivated,
                ],
                tags: ['identity', 'users', $to->value],
                actor: $actor,
            );

            return $user;
        });
    }

    /**
     * Whether moving to this state has to end the sessions that already exist.
     */
    private function endsAccess(UserStatus $to): bool
    {
        return $to === UserStatus::Suspended || $to === UserStatus::Deactivated;
    }

    /**
     * What the person reading the refusal can actually do next.
     *
     * "Not allowed" is true and useless. A terminal state and a state with
     * successors are different situations and read differently.
     */
    private static function explainRefusal(UserStatus $from, UserStatus $to): string
    {
        $allowed = UserStatusTransitions::allowedFrom($from);

        if ($allowed === []) {
            return sprintf(
                '%s is a final state, so nothing may follow it — including %s. Restoring access means a new account.',
                $from->label(),
                $to->label(),
            );
        }

        $labels = array_map(
            static fn (UserStatus $status): string => $status->label(),
            $allowed,
        );

        return sprintf(
            'From %s an account may become: %s.',
            $from->label(),
            implode(', ', $labels),
        );
    }
}
