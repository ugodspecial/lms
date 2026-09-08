<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Administration\Permissions;
use App\Http\Middleware\Concerns\RefusesUnauthorizedRequests;
use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
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
 * Area membership is decided by the area's access PERMISSION, read from
 * `config('platform.areas')` — not by a list of role names. Three reasons, in
 * order of importance:
 *
 * • ADR-09 puts the only role-name check in the whole authorization path inside
 *   `Gate::before`. A second one here would mean that admitting an Academic
 *   Admin to /admin requires editing this middleware as well as the matrix.
 *
 * • It makes the bypass work. `admin.panel.access` reaches Super Admin through
 *   `Gate::before`; a `hasAnyRole(['super-admin'])` list would have to be kept in
 *   step with it by hand.
 *
 * • The config already names the permission, and the registry already declares
 *   it. A hardcoded role list here was a third vocabulary — and Phase 0's list
 *   used slugs (`super-admin`, `finance-admin`, `support-agent`) that no seeder
 *   creates, so it would have refused every real user the moment roles existed.
 *
 * The authoritative ability checks remain in policies (ADR-14); this is the
 * coarse front door that keeps the fine-grained checks from being reached by the
 * wrong audience at all.
 *
 * Usage:  Route::prefix('admin')->middleware('area:admin')->group(...)
 */
final class AuthenticateArea
{
    use RefusesUnauthorizedRequests;

    /**
     * Areas that are not portals.
     *
     * `api` is authorized by the abilities on the caller's Sanctum token (§42),
     * not by a portal permission. Rejecting a machine client for lacking a web
     * role would make the API second-class and push integrators toward bypassing
     * it entirely — which is how a platform ends up with a scraper holding a
     * parent's session cookie.
     *
     * @var list<string>
     */
    private const NON_PORTAL_AREAS = ['api'];

    public function handle(Request $request, Closure $next, string ...$areas): Response
    {
        $area = $areas[0] ?? '';

        if (in_array($area, self::NON_PORTAL_AREAS, true)) {
            return $next($request);
        }

        /** @var array{path?: string, home?: string, permissions?: array<int, string>}|null $config */
        $config = config("platform.areas.{$area}");

        if (! is_array($config)) {
            // A typo in a route file must fail loudly in every environment.
            // Falling through to `allow` here would silently expose whatever the
            // route guards.
            abort(500, "Unknown portal area [{$area}].");
        }

        $required = $this->accessPermissions($area, $config['permissions'] ?? []);

        $user = $request->user();

        if ($user === null) {
            return $this->refuseUnauthenticated($request);
        }

        if (! $user instanceof Authorizable) {
            abort(500, 'The authenticated principal cannot be authorized: it does not implement '.Authorizable::class);
        }

        foreach ($required as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        // Names the area and nothing else. Listing the permissions that would have
        // worked turns a 403 into a map of the privilege model (§76).
        return $this->refuseForbidden(
            $request,
            'auth.area_forbidden',
            "You do not have access to the {$area} area.",
        );
    }

    /**
     * The permissions that admit a user to an area, validated against the
     * registry.
     *
     * Both failure modes here are configuration errors rather than user errors,
     * and both are silent if left unchecked: an area with no permission would
     * admit every authenticated user, and an area gated on a permission the
     * registry does not declare would admit nobody — the second is how a whole
     * portal goes dark after a rename and gets reported as "login is broken".
     *
     * @param  array<int, mixed>  $configured
     * @return list<string>
     */
    private function accessPermissions(string $area, array $configured): array
    {
        $required = [];

        foreach ($configured as $permission) {
            if (! is_string($permission) || $permission === '') {
                continue;
            }

            if (! Permissions::has($permission)) {
                abort(500, "Portal area [{$area}] is gated on unregistered permission [{$permission}].");
            }

            $required[] = $permission;
        }

        if ($required === []) {
            abort(500, "Portal area [{$area}] declares no access permission in config/platform.php.");
        }

        return $required;
    }
}
