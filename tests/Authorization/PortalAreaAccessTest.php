<?php

declare(strict_types=1);

namespace Tests\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Portal area authorization (§63, §66, §71).
 *
 * The `area:` middleware is the coarse front door that keeps the fine-grained
 * policy checks from being reached by the wrong audience at all: a tutor must
 * not reach /admin by editing a URL, and an administrator must not land in the
 * student portal holding an admin session.
 *
 * These run in Phase 0, before any role or permission exists, because the
 * property that matters most here is the **fail-closed** one. A gate that
 * defaults to "allow" while the permission system is still being built is the
 * kind of defect that survives into production unnoticed.
 *
 * Routes are registered per-test rather than in routes/web/*.php: an area route
 * that exists in Phase 0 with no controller behind it would be a dead link
 * (§63), which is exactly what the rest of the suite is guarding against.
 */
final class PortalAreaAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'student', 'parent', 'tutor', 'evaluator'] as $area) {
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
        foreach (['admin', 'student', 'parent', 'tutor', 'evaluator'] as $area) {
            $response = $this->get("/__probe/{$area}");

            // Fortify registers `login` in Phase 1. Until then the gate must send
            // a guest somewhere real rather than throwing a RouteNotFoundException
            // in their face — so the target is the home page, and it is asserted.
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

    public function test_an_authenticated_user_without_the_area_role_is_refused(): void
    {
        // Phase 0 has no role system, so this is the fail-closed guarantee: an
        // authenticated user must NOT be waved through an area gate simply
        // because the permission layer is not wired up yet.
        $this->actingAs($this->user());

        foreach (['admin', 'student', 'parent', 'tutor', 'evaluator'] as $area) {
            $response = $this->getJson("/__probe/{$area}");

            $response->assertForbidden();
            $response->assertJsonPath('error.code', 'auth.area_forbidden');
        }
    }

    public function test_the_refusal_page_does_not_leak_which_area_was_probed(): void
    {
        $this->actingAs($this->user());

        $response = $this->get('/__probe/admin');

        $response->assertForbidden();

        // §76: the rendered page must not carry a stack trace, a file path or the
        // internal role list. Telling a caller the exact roles that would have
        // worked turns a 403 into a map of the privilege model.
        foreach (['Illuminate\\', 'app/Http/Middleware', 'Stack trace', 'super-admin', 'AREA_ROLES'] as $leak) {
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
        // not by a portal role. The `api` area therefore must not reject a token
        // holder for lacking a web role — that would make machine clients
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

        // A new area added to config/platform.php without a matching role mapping
        // is a silent authorization hole: the gate would 500 instead of deciding.
        // Asserting 403 for every configured area makes that a test failure in the
        // very commit that introduces the new area.
        foreach (array_keys((array) config('platform.areas')) as $area) {
            Route::middleware(['web', "area:{$area}"])
                ->get("/__probe-configured/{$area}", fn () => 'unreachable')
                ->name("__probe.configured.{$area}");

            $response = $this->getJson("/__probe-configured/{$area}");

            $response->assertForbidden("The [{$area}] area is not gated — AuthenticateArea does not know it");
            $response->assertJsonPath('error.code', 'auth.area_forbidden');
        }
    }

    private function user(): User
    {
        // Deliberately unsaved: the gate must decide from the authenticated
        // principal alone, never from a database lookup that a missing table
        // would break.
        return new User([
            'name' => 'Probe User',
            'email' => 'probe@example.test',
        ]);
    }
}
