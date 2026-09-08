<?php

declare(strict_types=1);

namespace Tests\Authorization;

use App\Domain\Administration\Permissions;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Gate — where every `can()` on the platform is actually answered.
 *
 * The registry tests prove what SHOULD be granted. These prove what the running
 * application decides, which is a different thing: without the `Gate::before`
 * mapping in AuthServiceProvider, Laravel's Gate falls back to denying any
 * ability it has no definition for, so `$user->can('students.view')` would return
 * false for every Finance Officer who holds that exact permission. Nothing would
 * throw. Every check on the platform would simply say no, and the symptom would
 * be reported as "login is broken".
 *
 * The bypass is tested here too, including the part that is easy to get wrong:
 * it returns `null` rather than `false` whenever it declines to decide, because
 * `false` from a before callback is a veto that stops policies from running at
 * all.
 */
final class PermissionGateTest extends TestCase
{
    use RefreshDatabase;

    /** @var bool */
    protected $seed = true;

    public function test_a_permission_held_through_a_role_passes_can(): void
    {
        $finance = $this->user(Roles::FINANCE_OFFICER);

        $this->assertTrue($finance->can('payments.view'));
        $this->assertTrue($finance->can('admin.panel.access'));
        $this->assertTrue($finance->can('reports.finance'));

        // §2: no role management, and no hard deletion of core records.
        $this->assertFalse($finance->can('roles.manage'));
        $this->assertFalse($finance->can('students.delete'));
    }

    public function test_a_permission_nobody_holds_is_denied(): void
    {
        $administrator = $this->user(Roles::ADMINISTRATOR);

        $this->assertTrue($administrator->can('payments.refund'));
        $this->assertFalse($administrator->can('roles.manage'));
        $this->assertFalse($administrator->can('settings.manage.security'));
        $this->assertFalse($administrator->can('users.impersonate'));

        $this->assertFalse($this->user()->can('admin.panel.access'), 'A user holding no role must be denied, not defaulted to allowed.');
    }

    public function test_a_super_admin_passes_every_registered_check_except_participation(): void
    {
        $superAdmin = $this->user(Roles::SUPER_ADMIN);

        $refused = [];

        foreach (Permissions::all() as $permission) {
            if (! $superAdmin->can($permission)) {
                $refused[] = $permission;
            }
        }

        // ADR-09's whole point: a permission added to the registry is usable by an
        // administrator on deploy, with no data migration. Asserting the exception
        // list rather than "everything passes" is what keeps the two participant
        // permissions from being quietly folded back into the bypass.
        $this->assertSame([], array_values(array_diff($refused, Roles::PARTICIPANT_ONLY)));
    }

    public function test_the_participant_permissions_are_denied_even_to_a_super_admin(): void
    {
        $superAdmin = $this->user(Roles::SUPER_ADMIN);

        foreach (Roles::PARTICIPANT_ONLY as $permission) {
            $this->assertFalse(
                $superAdmin->can($permission),
                "{$permission} records that somebody took part in something. The bypass reaching it would let an administrator post a testimonial about a session they were not at (§88)."
            );
        }

        // And a Tutor, who does hold reviews.respond, still cannot review themselves:
        // the permission is granted, the policy is what refuses self-review.
        $this->assertTrue($this->user(Roles::TUTOR)->can('reviews.respond'));
        $this->assertFalse($this->user(Roles::TUTOR)->can('reviews.create'));
    }

    public function test_an_evaluator_cannot_approve_a_tutor_or_see_financial_data(): void
    {
        $evaluator = $this->user(Roles::EVALUATOR);

        // §28: evaluation and approval are different people, so one compromised or
        // bribed evaluator cannot both score and admit a tutor.
        $this->assertFalse($evaluator->can('tutors.approve'));
        $this->assertFalse($evaluator->can('tutors.reject'));

        // §49: the recruitment panel must not see what a tutor is paid, or the
        // interview becomes a salary negotiation.
        $this->assertFalse($evaluator->can('payments.view'));
        $this->assertFalse($evaluator->can('reports.finance'));

        // Enough to run an interview, and nothing to conclude one.
        $this->assertTrue($evaluator->can('tutors.applications.review'));
        $this->assertTrue($evaluator->can('tutors.evaluations.submit'));
        $this->assertTrue($evaluator->can('evaluator.portal.access'));
    }

    public function test_declining_returns_null_so_other_gates_still_run(): void
    {
        // An ability the registry does not declare is none of the before
        // callback's business. If it returned false instead of null, every policy
        // and every Gate::define on the platform would be dead for non-Super-Admins.
        Gate::define('probe.not-a-permission', fn (?User $user): bool => $user !== null);

        $this->assertTrue($this->user(Roles::STUDENT)->can('probe.not-a-permission'));
        $this->assertTrue($this->user(Roles::PARENT)->can('probe.not-a-permission'));
        $this->assertFalse(Gate::forUser($this->user())->check('probe.absent'));
    }

    public function test_the_bypass_also_covers_abilities_the_registry_never_declared(): void
    {
        // The cost of ADR-09, stated rather than discovered later. The bypass
        // exists so a new permission works before it is seeded, and it cannot tell
        // that apart from an unrelated ability — so a Super Admin passes even a
        // gate defined to deny. A policy must not rely on the bypass being absent.
        Gate::define('probe.always-denied', fn (): bool => false);

        $this->assertTrue($this->user(Roles::SUPER_ADMIN)->can('probe.always-denied'));
        $this->assertFalse($this->user(Roles::ADMINISTRATOR)->can('probe.always-denied'));
    }

    public function test_the_permission_middleware_admits_a_holder_and_refuses_everyone_else(): void
    {
        $this->probe('permission:payments.view');

        $this->actingAs($this->user(Roles::FINANCE_OFFICER))->getJson('/__perm/probe')->assertOk();
        $this->actingAs($this->user(Roles::SUPER_ADMIN))->getJson('/__perm/probe')->assertOk();

        $refused = $this->actingAs($this->user(Roles::EVALUATOR))->getJson('/__perm/probe');

        $refused->assertForbidden();
        $refused->assertJsonPath('error.code', 'auth.permission_denied');

        // The message names no permission: telling a caller what they lack turns a
        // 403 into a map of the privilege model (§76).
        $refused->assertDontSee('payments.view', false);
    }

    public function test_the_permission_middleware_treats_several_permissions_as_alternatives(): void
    {
        $this->probe('permission:payments.refund,students.view');

        // Holds both.
        $this->actingAs($this->user(Roles::FINANCE_OFFICER))->getJson('/__perm/probe')->assertOk();

        // Holds students.view only. This is the assertion that distinguishes
        // any-of from all-of: if the middleware required every listed permission,
        // a Parent would be locked out of a route they are entitled to reach.
        $this->actingAs($this->user(Roles::PARENT))->getJson('/__perm/probe')->assertOk();

        // Holds neither.
        $this->actingAs($this->user(Roles::EVALUATOR))->getJson('/__perm/probe')->assertForbidden();
    }

    public function test_the_permission_middleware_answers_a_guest_with_401_not_403(): void
    {
        $this->probe('permission:payments.view');

        // Conflating the two is how an API client ends up retrying a request that
        // could never succeed: 403 says "you may not", 401 says "I do not know
        // who you are", and only the second is fixable by authenticating.
        $this->getJson('/__perm/probe')->assertUnauthorized()->assertJsonPath('error.code', 'auth.unauthenticated');
    }

    public function test_an_unregistered_permission_on_a_route_is_a_server_error_not_a_silent_denial(): void
    {
        $this->probe('permission:payments.vew');

        // A typo. Left unchecked it would deny the route to everyone, forever, with
        // no exception and no log entry — indistinguishable from a permission that
        // was deliberately withheld, and therefore very hard to find.
        $this->actingAs($this->user(Roles::SUPER_ADMIN))->getJson('/__perm/probe')->assertStatus(500);
    }

    public function test_the_permission_middleware_attached_with_no_permission_is_a_server_error(): void
    {
        $this->probe('permission');

        // Denying would be safe but would look like a permission bug to whoever is
        // debugging it, and it is not one: it is a route that was never finished.
        $this->actingAs($this->user(Roles::SUPER_ADMIN))->getJson('/__perm/probe')->assertStatus(500);
    }

    public function test_every_scoped_permission_is_actually_granted_to_the_role_it_is_scoped_for(): void
    {
        // Closes the loop between the two halves of the design. A scope note on a
        // permission a role does not hold describes a rule that never applies,
        // which is how a policy ends up written for the wrong audience.
        foreach (Roles::ALL as $role) {
            $held = $this->user($role);

            foreach (Roles::permissionsFor($role) as $permission) {
                $this->assertTrue(
                    $held->can($permission),
                    "{$role} is bundled with [{$permission}] but the Gate refuses it, so the seeder and the registry disagree."
                );
            }
        }
    }

    private function probe(string $middleware): void
    {
        Route::middleware(['web', $middleware])
            ->get('/__perm/probe', fn () => 'reachable')
            ->name('__perm.probe');
    }

    private function user(?string $role = null): User
    {
        $user = new User;

        $user->name = 'Gate Probe';
        $user->email = 'gate+'.Str::lower(Str::random(10)).'@example.test';
        $user->password = 'probe-password';

        $user->save();

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
