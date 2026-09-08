<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route group to one portal area: student, parent, tutor, evaluator,
 * admin, api (§63, §66).
 *
 * Why this exists: the areas have different layouts, different session
 * lifetimes, different rate limits and, most importantly, **different
 * audiences**. A tutor must not be able to reach /admin by editing a URL, and
 * an administrator must not accidentally land in the student portal with an
 * admin session that outlives the browser.
 *
 * Area membership is derived from the authenticated user's roles, not from the
 * URL — the URL declares the *area*, this middleware proves the *person* belongs
 * to it. The authoritative ability checks remain in policies (ADR-14); this is
 * the coarse front door that keeps the fine-grained checks from being reached
 * by the wrong audience at all.
 *
 * Usage:  Route::prefix('admin')->middleware('area:admin')->group(...)
 */
final class AuthenticateArea
{
    /** Role that grants access to each area. */
    private const AREA_ROLES = [
        'student' => ['student'],
        'parent' => ['parent'],
        'tutor' => ['tutor'],
        'evaluator' => ['evaluator'],
        'admin' => ['super-admin', 'admin', 'academic-admin', 'finance-admin', 'support-agent'],
        'api' => [],
    ];

    public function handle(Request $request, Closure $next, string ...$areas): Response
    {
        $area = $areas[0] ?? '';

        if (! array_key_exists($area, self::AREA_ROLES)) {
            abort(500, "Unknown portal area [{$area}].");
        }

        // The api area is guarded by Sanctum rather than by role: a token's
        // abilities define what it may do (§42).
        if ($area === 'api') {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => ['code' => 'auth.unauthenticated', 'message' => 'Authentication required.'],
                ], 401);
            }

            // `login` is registered by Fortify in Phase 1; fall back instead of
            // turning a redirect into a RouteNotFoundException.
            return redirect()->guest(
                Route::has('login') ? route('login') : route('home')
            );
        }

        $allowed = self::AREA_ROLES[$area];

        // hasAnyRole() arrives with Spatie Permission in Phase 1. Until the
        // User model has it, access is denied rather than accidentally allowed
        // — failing closed is the only safe default for a gate.
        if (! method_exists($user, 'hasAnyRole') || ! $user->hasAnyRole($allowed)) {
            return $request->expectsJson()
                ? response()->json(['error' => ['code' => 'auth.area_forbidden', 'message' => "You do not have access to the {$area} area."]], 403)
                : abort(403, "You do not have access to the {$area} area.");
        }

        return $next($request);
    }
}
