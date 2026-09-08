<?php

declare(strict_types=1);

namespace Tests\Authorization;

use App\Domain\Administration\Permissions;
use App\Domain\Administration\Roles;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Portal area authorization (§63, §66, §71).
 *
 * The `area:` middleware is the coarse front door that keeps the fine-grained
 * policy checks from being reached by the wrong audience at all: a tutor must
 * not reach /admin by editing a URL, and an administrator must not land in the
 * student portal holding an admin session.
 *
 * Phase 0 could only test the DENIAL half of this, because no role or permission
 * existed yet and the gate was deliberately fail-closed. Phase 1 has the
 * registry, so these now cover both halves: who is refused, and who is admitted —
 * including the two cases that are easy to get wrong and expensive when they are:
 * the Super Admin bypass reaching an area gate at all, and a user holding several
 * roles being admitted to each of their areas rather than only one.
 *
 * These tests need a database. That is a change from Phase 0, where the
 * authenticated principal was deliberately unsaved so the gate could not depend
 * on a table that might not exist. It now can and must: area membership is a
 * permission, permissions live in a table, and there is no way to answer "may
 * this person be here?" without reading it. The fail-closed property is still
 * asserted — see the test for a user holding nothing.
 *
 * Routes are registered per-test rather than in routes/web/*.php: an area route
 * that exists with no controller behind it would be a dead link (§63).
 */
final class PortalAreaAccessTest extends TestCase
{
    use RefreshDatabase;

    // Seeding is owned by Tests\TestCase, which explains why it cannot live here:
    // `migrate:fresh --seed` runs once per process and takes the flag from
    // whichever class happens to run first.

    /** The five portal areas. `api` is not one: it is authorized by token ability (§42). */
    private const AREAS = ['admin', 'student', 'parent', 'tutor', 'evaluator'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::AREAS as $area) {
            Route::middleware(['web', "area:{$area}"])
                ->get("/__probe/{$area}", fn () => "inside:{$area}")
                ->name("__probe.{$area}");
        }

        Route::middleware(['web', 'area:not-a-real-area'])
            ->get('/__probe/bogus', fn () => 'unreachable')
            ->name('__probe.bogus');
    }

    public function test_a_guest_is_redirected_away_from_every_area(): void
    {
        foreach (self::AREAS as $area) {
            $response = $this->get("/__probe/{$area}");

            // Fortify registers `login` later in Phase 1. Until then the gate must
            // send a guest somewhere real rather than throwing a
            // RouteNotFoundException in their face — so the target is the home
            // page, and it is asserted rather than assumed.
            $response->assertRedirect(route('home'));
        }
    }

    public function test_a_guest_gets_401_not_a_redirect_when_asking_for_json(): void
    {
        // An API client cannot follow a login redirect; it needs a status code it
        // can branch on (§42).
        $response = $this->getJson('/__probe/admin');

        $response->assertUnauthorized();
        $response->assertJsonPath('error.code', 'auth.unauthenticated');
    }

    public function test_an_authenticated_user_holding_no_permission_is_refused_everywhere(): void
    {
        // The fail-closed guarantee, now tested against a real permission system
        // rather than an absent one: a user with no roles must be refused every
        // area, including the ones whose permission names look like they might
        // default to allowed.
        $this->actingAs($this->user());

        foreach (self::AREAS as $area) {
            $response = $this->getJson("/__probe/{$area}");

            $response->assertForbidden();
            $response->assertJsonPath('error.code', 'auth.area_forbidden');
        }
    }

    public function test_a_user_is_admitted_to_the_area_their_permission_covers(): void
    {
        $this->actingAs($this->user(Roles::STUDENT));

        $this->getJson('/__probe/student')->assertOk()->assertSee('inside:student');

        foreach (['admin', 'parent', 'tutor', 'evaluator'] as $area) {
            $this->getJson("/__probe/{$area}")
                ->assertForbidden("A Student reached the {$area} area.")
                ->assertJsonPath('error.code', 'auth.area_forbidden');
        }
    }

    public function test_a_parent_is_admitted_to_the_parent_area_and_not_the_student_one(): void
    {
        // docs/05 §3.2 grants student.portal.access to every staff role and to
        // Student, but not to Parent: a guardian sees their child's learning
        // through the parent portal, which is scoped to their children (§58), not
        // through the portal the child uses.
        $this->actingAs($this->user(Roles::PARENT));

        $this->getJson('/__probe/parent')->assertOk()->assertSee('inside:parent');
        $this->getJson('/__probe/student')->assertForbidden();
        $this->getJson('/__probe/admin')->assertForbidden();
    }

    public function test_a_super_admin_reaches_every_area_through_the_bypass(): void
    {
        $this->actingAs($this->user(Roles::SUPER_ADMIN));

        foreach (self::AREAS as $area) {
            $this->getJson("/__probe/{$area}")->assertOk(
                "Super Admin was refused the {$area} area, so the Gate::before bypass is not reaching AuthenticateArea."
            );
        }
    }

    public function test_a_user_holding_several_roles_reaches_each_of_their_areas(): void
    {
        // docs/05 §2: a Tutor who is also a Parent. There is no "primary role", so
        // admission must be the union of what each role grants — not whichever role
        // was assigned last, and not whichever the switcher happens to be showing.
        $user = $this->user(Roles::TUTOR);
        $user->assignRole(Roles::PARENT);

        $this->actingAs($user);

        $this->getJson('/__probe/tutor')->assertOk();
        $this->getJson('/__probe/parent')->assertOk();

        // A Tutor also holds student.portal.access: §3.2 gives it to every staff
        // role, so staff can reach the learning area when supporting a student.
        $this->getJson('/__probe/student')->assertOk();

        $this->getJson('/__probe/admin')->assertForbidden();
        $this->getJson('/__probe/evaluator')->assertForbidden();
    }

    public function test_the_refusal_page_does_not_leak_which_area_was_probed(): void
    {
        $this->actingAs($this->user());

        $response = $this->get('/__probe/admin');

        $response->assertForbidden();

        // §76: the rendered page must not carry a stack trace, a file path, or the
        // permission that would have worked. Telling a caller the exact permission
        // turns a 403 into a map of the privilege model.
        foreach ([
            'Illuminate\\',
            'app/Http/Middleware',
            'Stack trace',
            'admin.panel.access',
            'Super Admin',
            'platform.areas',
        ] as $leak) {
            $response->assertDontSee($leak, false);
        }
    }

    public function test_an_unknown_area_is_a_server_error_not_an_open_door(): void
    {
        // A typo in a route file must fail loudly in every environment. Falling
        // through to `allow` here would silently expose whatever the route guards.
        $this->actingAs($this->user());

        $response = $this->getJson('/__probe/bogus');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('unreachable', $response->getContent());
    }

    public function test_the_area_gate_applies_to_the_whole_group_not_just_one_route(): void
    {
        Route::middleware(['web', 'area:parent'])
            ->prefix('__probe-group')
            ->group(function (): void {
                Route::get('/one', fn () => 'group:one')->name('__probe.group.one');
                Route::get('/two', fn () => 'group:two')->name('__probe.group.two');
            });

        $this->actingAs($this->user());

        // A gate attached per-route instead of per-group is how one forgotten
        // route becomes an unauthenticated hole in an otherwise protected area.
        $this->getJson('/__probe-group/one')->assertForbidden();
        $this->getJson('/__probe-group/two')->assertForbidden();
    }

    public function test_the_api_area_is_guarded_by_token_ability_not_by_role(): void
    {
        // §42: an API caller is authorized by the abilities on its Sanctum token,
        // not by a portal permission. The `api` area therefore must not reject a
        // caller for lacking a web role — that would make machine clients
        // second-class and push integrators toward bypassing the API entirely.
        Route::middleware('area:api')
            ->get('/__probe/api-area', fn () => 'api-area-reachable')
            ->name('__probe.api');

        $response = $this->getJson('/__probe/api-area');

        $response->assertOk();
        $response->assertSee('api-area-reachable');
    }

    public function test_every_configured_area_is_gated_rather_than_unknown(): void
    {
        $this->actingAs($this->user());

        // A new area added to config/platform.php without a permission the registry
        // declares is a silent authorization hole: the gate would 500 instead of
        // deciding. Asserting 403 for every configured area makes that a test
        // failure in the very commit that introduces the new area.
        foreach (array_keys((array) config('platform.areas')) as $area) {
            Route::middleware(['web', "area:{$area}"])
                ->get("/__probe-configured/{$area}", fn () => 'unreachable')
                ->name("__probe.configured.{$area}");

            $response = $this->getJson("/__probe-configured/{$area}");

            $response->assertForbidden("The [{$area}] area is not gated — AuthenticateArea does not know it");
            $response->assertJsonPath('error.code', 'auth.area_forbidden');
        }
    }

    public function test_every_configured_area_gates_on_a_registered_permission(): void
    {
        // The other half of the same hole, checked without a request: an area
        // configured with a permission the registry does not declare would admit
        // nobody, and would be reported by users as "login is broken" rather than
        // as a configuration error.
        foreach ((array) config('platform.areas') as $area => $config) {
            $permissions = (array) ($config['permissions'] ?? []);

            $this->assertNotSame([], $permissions, "Area [{$area}] declares no access permission.");

            foreach ($permissions as $permission) {
                $this->assertTrue(
                    Permissions::has((string) $permission),
                    "Area [{$area}] is gated on [{$permission}], which is not in the registry."
                );
            }
        }
    }

    /**
     * A persisted user, optionally holding one role.
     *
     * Attributes are assigned directly rather than through a factory, for the same
     * reason DemoUserSeeder does it: `email_verified_at` is deliberately not
     * fillable, and a test should not depend on how a factory gets around that.
     */
    private function user(?string $role = null): User
    {
        $user = new User;

        $user->name = 'Probe User';
        $user->email = 'probe+'.Str::lower(Str::random(10)).'@example.test';
        $user->password = 'probe-password';

        $user->save();

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
