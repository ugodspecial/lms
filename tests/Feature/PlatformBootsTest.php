<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Time\TimezonePresenter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 0 smoke test (docs/10, Phase 0 exit gate).
 *
 * These assert what has to be true before anything else can be: the application
 * boots, its routes are registered, its views compile, its compiled assets exist,
 * and its request-level protections are actually applied.
 *
 * They touch no database and call no external service, so a failure here is
 * always a real defect in the skeleton rather than a missing credential.
 */
final class PlatformBootsTest extends TestCase
{
    public function test_the_application_boots(): void
    {
        $this->assertInstanceOf(Application::class, $this->app);
        $this->assertSame('testing', $this->app->environment());
    }

    public function test_the_home_page_renders(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        // Escaped comparison (the assertSee default): the name is rendered through
        // {{ }}, so Blade has already turned any & into &amp;. Asserting the raw
        // config string against raw HTML would never match once a name contains
        // an ampersand.
        $response->assertSee((string) config('platform.name'));

        // Compiled CSS must be linked, or every page renders unstyled. A missing
        // manifest is the classic symptom of deploying without `npm run build`
        // on a host with no Node runtime (ADR-13).
        $response->assertSee('/build/assets/app-', false);
    }

    public function test_the_home_page_reports_module_status_honestly(): void
    {
        $response = $this->get('/');

        $response->assertOk();

        foreach ((array) config('platform.modules') as $module) {
            // Escaped, for the same reason as above and more urgently here: six of
            // the nine labels contain an ampersand ("Identity & Access", "Tutors &
            // Booking", ...) and the page renders them as "Identity &amp; Access".
            // assertSee(..., false) compares the literal config string against raw
            // HTML, so every one of those six failed no matter what the page did.
            $response->assertSee((string) $module['label']);
        }

        // §63: a module may only be presented as navigable if its route really
        // exists. In Phase 0 none of them do, so no "Open" control may render.
        $this->assertFalse(Route::has('admin.dashboard'), 'Phase 0 must not register an admin dashboard');
        $this->assertFalse(Route::has('student.dashboard'), 'Phase 0 must not register a student dashboard');

        $response->assertDontSee('Open', false);
    }

    public function test_the_liveness_probe_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_api_status_probe_responds_with_utc_time(): void
    {
        $response = $this->getJson('/api/v1/status');

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'api' => 'v1']);

        // §62: the API speaks UTC and lets the client convert. Returning a
        // server-local time would bake one deployment's timezone into every
        // consumer's display logic.
        $this->assertMatchesRegularExpression(
            '/(?:Z|\+00:00)$/',
            (string) $response->json('time')
        );
    }

    public function test_webhook_routes_are_exempt_from_csrf(): void
    {
        // Registered inside the `webhooks` group exactly as a real provider route
        // will be, so this proves the group is mounted and usable.
        Route::middleware('webhooks')->post('/webhooks/__probe', fn () => response()->noContent());

        // Paystack cannot obtain a CSRF token. If the exemption were missing,
        // every real webhook would be rejected with 419 and every payment would
        // stay "pending" while the customer's card had already been charged (§41).
        $this->post('/webhooks/__probe')->assertNoContent();
    }

    public function test_csrf_protection_is_still_enforced_on_web_routes(): void
    {
        // The counterpart to the exemption above: `webhooks/*` is exempt, but the
        // web group must still reject a cross-site POST. Without this test the
        // exemption could be widened by accident and nobody would notice.
        Route::middleware('web')->post('/__csrf-probe', fn () => 'submitted');

        $this->post('/__csrf-probe')->assertStatus(419);

        // A correctly-tokened request goes through. The token is seeded into the
        // session explicitly rather than read from csrf_token(), which is not
        // populated until a session has actually been started.
        $token = 'phase0-csrf-token';

        $this->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->post('/__csrf-probe')
            ->assertOk();
    }

    public function test_security_headers_are_present_on_web_responses(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('geolocation=()', (string) $response->headers->get('Permissions-Policy'));

        // HSTS is only sent over a genuinely secure connection. Emitting it over
        // plain HTTP behind a misconfigured proxy can strand a site for a year.
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
    }

    public function test_the_viewer_timezone_is_resolved_for_every_request(): void
    {
        config(['platform.timezone.default' => 'Africa/Lagos']);

        // An explicit ?tz= override must be honoured, because a tutor teaching a
        // student abroad needs to read a timetable in the student's zone (§62).
        $this->get('/?tz=Europe/London')->assertOk();
        $this->assertSame('Europe/London', app(TimezonePresenter::class)->current());

        // And it must never crash or be applied when the value is
        // attacker-controlled garbage: an invalid timezone reaching Carbon is a
        // fatal on every page, not a validation error.
        $this->get('/?tz=Not/AZone')->assertOk();
        $this->assertSame('Africa/Lagos', app(TimezonePresenter::class)->current());

        $this->get('/?tz=../../etc/passwd')->assertOk();
        $this->assertSame('Africa/Lagos', app(TimezonePresenter::class)->current());

        $this->get('/')->assertOk();
        $this->assertSame('Africa/Lagos', app(TimezonePresenter::class)->current());
    }

    public function test_an_unknown_page_returns_a_branded_404_without_leaking_internals(): void
    {
        $response = $this->get('/this-route-does-not-exist');

        $response->assertNotFound();
        $response->assertSee('We could not find that page', false);

        // §76: never echo the exception class, a file path or a SQL fragment.
        // A 404 that says "does not exist" also becomes an enumeration oracle for
        // student records, so the wording is asserted too.
        foreach ([
            'SQLSTATE', 'vendor/', 'app/Http', 'Stack trace', 'Illuminate\\',
            'NotFoundHttpException', 'does not exist',
        ] as $leak) {
            $response->assertDontSee($leak, false);
        }
    }

    public function test_every_named_route_generates_a_url(): void
    {
        // A named route that cannot generate a URL (missing parameter, or a name a
        // view references but no route registers) throws at render time rather
        // than at deploy time — i.e. in front of a user, not in CI.
        $names = array_keys(Route::getRoutes()->getRoutesByName());

        $this->assertNotSame([], $names);

        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if ($route->parameterNames() === []) {
                $this->assertNotEmpty(route($name), "Named route [{$name}] produced an empty URL");
            }
        }

        $this->assertTrue(Route::has('home'));
        $this->assertTrue(Route::has('api.v1.status'));
        $this->assertContains('home', $names);
    }
}
