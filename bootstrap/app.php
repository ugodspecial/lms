<?php

use App\Exceptions\PlatformException;
use App\Http\Middleware\AuthenticateArea;
use App\Http\Middleware\EnsureIntegrationConfigured;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetUserTimezone;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
        then: function (): void {
            // Webhooks are CSRF-exempt but HMAC-verified instead (§42, docs/08 §1.5).
            Route::middleware('webhooks')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Shared hosting frequently sits behind a proxy/CDN that rewrites
        // REMOTE_ADDR. Trusting proxies is required for correct client IPs in the
        // audit log and for secure-cookie detection behind SSL termination (§96).
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        $middleware->validateCsrfTokens(except: [
            // Paystack signs its webhooks with HMAC-SHA512 over the raw body,
            // which is a stronger guarantee than a CSRF token (docs/08 §1.5).
            'webhooks/*',
        ]);

        // Portal scoping: every authenticated area re-authorizes on every
        // request, including Livewire updates (docs/01 §5.1).
        $middleware->alias([
            'area' => AuthenticateArea::class,
            'permission' => EnsurePermission::class,
            'timezone' => SetUserTimezone::class,
            'integration.connected' => EnsureIntegrationConfigured::class,
        ]);

        // Webhook routes: no CSRF (they are HMAC-verified instead), no session,
        // no cookie encryption of the body. The signature middleware for each
        // provider is attached per-route so the raw body stays intact (§42).
        $middleware->group('webhooks', [
            ThrottleRequests::class.':webhooks',
        ]);

        $middleware->web(append: [
            SetUserTimezone::class,
            SecurityHeaders::class,
        ]);

        // Fortify registers `login` in Phase 1; until then fall back rather than
        // throwing a RouteNotFoundException in a guest's face.
        $middleware->redirectGuestsTo(
            fn () => Route::has('login') ? route('login') : route('home')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Domain exceptions carry a machine-readable code so the future /api/v1
        // and the web UI can both react precisely (§68, §76).
        $exceptions->render(function (PlatformException $e, Request $request) {
            if ($request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson()) {
                return response()->json($e->toApiResponse(), $e->getStatusCode());
            }

            return null; // fall through to the standard web handling
        });

        /*
         * HTTP errors (403, 404, 419, 429, 500, 503) need no hook here: Laravel
         * discovers resources/views/errors/{status}.blade.php automatically and
         * renders it in place of its own page. Those views are branded and are
         * written to disclose nothing about the cause (§76).
         *
         * Re-rendering them from a `respond()` callback as well would set the body
         * a second time on a response whose headers are already fixed — a wasted
         * render, and a subtle source of "why did my header not apply" bugs.
         */
    })->create();
