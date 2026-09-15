<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UserStatusService;
use App\Exceptions\PlatformException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * An account's lifecycle state, and everything that has to happen with it
 * (docs/02 §1, docs/06 §2, docs/04 §11).
 *
 * The transitions themselves are a table in UserStatusTransitionsTest, checked
 * exhaustively and without a database. What is here is the part that only exists
 * once there is one:
 *
 *   • activation follows verification, because `pending` is how the platform keeps
 *     an unproved address out of directories and portals;
 *   • suspension and deactivation delete the session rows, because changing the
 *     column changes nothing for a browser that is already signed in — the session
 *     is what authenticates the next request;
 *   • deactivation soft-deletes, because academic and financial rows reference
 *     `users` with ON DELETE RESTRICT and a hard delete would either fail or orphan
 *     a child's history (§58);
 *   • nobody closes their own account, including by omission of an explicit actor.
 *
 * Every move writes an audit entry carrying the reason. A trail that records a
 * suspension with no stated reason is indistinguishable from one that was edited,
 * which is the property an audit log exists to not have (§56).
 */
final class UserStatusTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    public function test_a_verified_pending_account_can_be_activated(): void
    {
        $user = $this->makeUser();

        $this->assertSame(UserStatus::Pending, $user->refresh()->status);

        $this->service()->activate($user, 'Email verified', $this->administrator());

        $this->assertSame(UserStatus::Active, $user->refresh()->status);
    }

    public function test_an_unverified_account_cannot_be_activated(): void
    {
        $user = $this->makeUser(['email_verified_at' => null]);

        try {
            $this->service()->activate($user, 'An administrator thought it was fine', $this->administrator());

            $this->fail('Activation must follow verification.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.email_not_verified', $exception->getErrorCode());
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertArrayHasKey('email', $exception->getErrors());
        }

        $this->assertSame(UserStatus::Pending, $user->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('event', 'users.status_changed')->count());
    }

    public function test_suspending_an_account_removes_the_sessions_that_already_exist(): void
    {
        $user = $this->makeUser();
        $somebodyElse = $this->makeUser();

        $this->service()->activate($user, 'Email verified');

        $this->sessionFor($user);
        $this->sessionFor($user);
        $this->sessionFor($somebodyElse);

        $this->service()->suspend($user, 'Chargeback dispute under investigation', $this->administrator());

        // The point of the assertion is that a status change is not only a status
        // change: two live sessions belonged to an account that may no longer use
        // the platform, and they would have kept authenticating requests until they
        // expired on their own.
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $somebodyElse->id)->count());
        $this->assertSame(UserStatus::Suspended, $user->refresh()->status);
    }

    public function test_suspension_is_reversible_and_the_reason_is_kept_both_ways(): void
    {
        $user = $this->makeUser();
        $administrator = $this->administrator();

        $this->service()->activate($user, 'Email verified', $administrator);
        $this->service()->suspend($user, 'Chargeback dispute under investigation', $administrator);
        $this->service()->reinstate($user, 'Dispute resolved in the customer\'s favour', $administrator);

        $this->assertSame(UserStatus::Active, $user->refresh()->status);

        $reasons = AuditLog::query()
            ->where('event', 'users.status_changed')
            ->orderBy('id')
            ->get()
            ->map(static fn (AuditLog $log): string => (string) ($log->new_values['reason'] ?? ''))
            ->all();

        $this->assertSame([
            'Email verified',
            'Chargeback dispute under investigation',
            "Dispute resolved in the customer's favour",
        ], $reasons);
    }

    public function test_a_status_change_without_a_reason_is_refused(): void
    {
        $user = $this->makeUser();

        $this->service()->activate($user, 'Email verified');

        try {
            $this->service()->suspend($user, '   ');

            $this->fail('A status change needs a reason.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.status_reason_required', $exception->getErrorCode());
        }

        $this->assertSame(UserStatus::Active, $user->refresh()->status);
    }

    public function test_moving_to_the_state_the_account_is_already_in_is_refused(): void
    {
        $user = $this->makeUser();

        $this->service()->activate($user, 'Email verified');

        try {
            $this->service()->activate($user, 'Email verified again');

            $this->fail('The account is already active.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.status_unchanged', $exception->getErrorCode());
            $this->assertSame('active', $exception->getContext()['status']);
        }

        // Refusing it matters because accepting it would write an audit entry
        // claiming a change that did not happen.
        $this->assertSame(1, AuditLog::query()->where('event', 'users.status_changed')->count());
    }

    public function test_a_pending_account_cannot_be_suspended(): void
    {
        $user = $this->makeUser();

        try {
            $this->service()->suspend($user, 'Abuse report', $this->administrator());

            $this->fail('Suspension answers conduct by somebody who can act.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.status_transition_not_allowed', $exception->getErrorCode());

            // The refusal has to say what IS possible, or the person reading it has
            // to go and read the state machine.
            $this->assertStringContainsString(
                'Awaiting verification',
                $exception->getErrors()['status'][0],
            );
            $this->assertStringContainsString('Deactivated', $exception->getErrors()['status'][0]);
        }

        $this->assertSame(UserStatus::Pending, $user->refresh()->status);
    }

    public function test_a_deactivated_account_is_terminal(): void
    {
        $user = $this->makeUser();
        $administrator = $this->administrator();

        $this->service()->activate($user, 'Email verified', $administrator);
        $this->service()->deactivate($user, 'Left the organization', $administrator);

        foreach ([
            'reinstate' => 'Deactivated is the state this platform uses instead of deletion.',
            'suspend' => 'A closed account cannot be suspended.',
        ] as $method => $why) {
            try {
                $this->service()->{$method}($user, 'Somebody changed their mind', $administrator);

                $this->fail($why);
            } catch (PlatformException $exception) {
                $this->assertSame('identity.status_transition_not_allowed', $exception->getErrorCode(), $why);
                $this->assertStringContainsString('final state', $exception->getErrors()['status'][0], $why);
            }
        }
    }

    public function test_deactivation_soft_deletes_rather_than_removes(): void
    {
        $user = $this->makeUser();

        $this->service()->activate($user, 'Email verified');
        $this->service()->deactivate($user, 'Left the organization', $this->administrator());

        $refreshed = $user->refresh();

        $this->assertSame(UserStatus::Deactivated, $refreshed->status);
        $this->assertNotNull($refreshed->deleted_at);

        // Not gone. A hard delete would either fail on the ON DELETE RESTRICT rows
        // that reference this user or orphan a child's academic history (§58).
        $this->assertSame(0, User::query()->whereKey($user->getKey())->count());
        $this->assertSame(1, User::withTrashed()->whereKey($user->getKey())->count());
        $this->assertSame(1, DB::table('users')->where('id', $user->id)->count());
    }

    public function test_nobody_may_deactivate_their_own_account(): void
    {
        $administrator = $this->administrator();

        try {
            $this->service()->deactivate($administrator, 'I am leaving', $administrator);

            $this->fail('Cannot deactivate self (docs/06 §2).');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.self_deactivation', $exception->getErrorCode());
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame(UserStatus::Pending, $administrator->refresh()->status);
    }

    public function test_the_authenticated_administrator_may_not_deactivate_themselves_by_omission_either(): void
    {
        // The common call shape passes no actor at all and lets the audit entry take
        // the authenticated user. Checking only the explicit argument would leave
        // exactly that shape unguarded, which is the shape a controller uses.
        $administrator = $this->administrator();

        Auth::login($administrator);

        try {
            $this->service()->deactivate($administrator, 'I am leaving');

            $this->fail('Cannot deactivate self, whoever the actor is resolved from.');
        } catch (PlatformException $exception) {
            $this->assertSame('identity.self_deactivation', $exception->getErrorCode());
        }

        $this->assertSame(UserStatus::Pending, $administrator->refresh()->status);
    }

    public function test_deactivation_ends_the_sessions_too(): void
    {
        $user = $this->makeUser();

        $this->service()->activate($user, 'Email verified');
        $this->sessionFor($user);

        $this->service()->deactivate($user, 'Left the organization', $this->administrator());

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_activation_does_not_revoke_anything(): void
    {
        // Letting somebody in is not an access-ending move. Revoking here would sign
        // a person out at the moment they were approved.
        $user = $this->makeUser();

        $this->sessionFor($user);

        $this->service()->activate($user, 'Email verified', $this->administrator());

        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_the_audit_entry_records_the_move_the_reason_and_who_made_it(): void
    {
        $user = $this->makeUser();
        $administrator = $this->administrator();

        $this->service()->activate($user, 'Email verified', $administrator);
        $this->sessionFor($user);
        $this->service()->suspend($user, 'Chargeback dispute under investigation', $administrator);

        $entry = AuditLog::query()->where('event', 'users.status_changed')->latest('id')->first();

        $this->assertInstanceOf(AuditLog::class, $entry);
        // Cast on both sides: the actor's id is the in-memory integer from the
        // INSERT, the entry's came back out of MySQL, and the two are the same
        // identifier rather than necessarily the same PHP type.
        $this->assertSame((int) $administrator->id, (int) $entry->user_id);
        $this->assertSame(User::class, $entry->auditable_type);
        $this->assertSame((int) $user->id, (int) $entry->auditable_id);

        $this->assertSame(['status' => 'active'], $entry->old_values);
        $this->assertSame('suspended', $entry->new_values['status']);
        $this->assertSame('Chargeback dispute under investigation', $entry->new_values['reason']);
        $this->assertSame(1, $entry->new_values['sessions_revoked']);
        $this->assertFalse($entry->new_values['soft_deleted']);

        $this->assertContains('identity', $entry->tagList());
        $this->assertContains('users', $entry->tagList());
        $this->assertContains('suspended', $entry->tagList());
    }

    public function test_a_deactivation_records_that_the_account_was_soft_deleted(): void
    {
        $user = $this->makeUser();

        $this->service()->deactivate($user, 'Left the organization', $this->administrator());

        $entry = AuditLog::query()->where('event', 'users.status_changed')->latest('id')->first();

        $this->assertInstanceOf(AuditLog::class, $entry);
        $this->assertSame(['status' => 'pending'], $entry->old_values);
        $this->assertTrue($entry->new_values['soft_deleted']);
        $this->assertSame('deactivated', $entry->new_values['status']);
    }

    private function service(): UserStatusService
    {
        return app(UserStatusService::class);
    }

    private function administrator(): User
    {
        $administrator = $this->makeUser();
        $administrator->assignRole(Roles::ADMINISTRATOR);

        return $administrator;
    }

    /**
     * A signed-in session, written the way the session handler writes it.
     *
     * The suite runs with SESSION_DRIVER=array, so a request would not leave a row
     * behind — and the row is the thing under test. What is being asserted is the
     * query that removes it, which is the same query in every environment; in
     * production the driver is `database` and these rows are real sign-ins.
     */
    private function sessionFor(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }
}
