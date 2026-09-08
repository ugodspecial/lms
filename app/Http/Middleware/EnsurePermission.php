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
 * Require one of a set of permissions to reach a route.
 *
 *     Route::get('/admin/students', ...)->middleware('permission:students.view');
 *     Route::post('/admin/refunds', ...)->middleware('permission:refunds.issue,commerce.refund');
 *
 * Any-of, because a route is usually reachable by whoever can do the thing it
 * does, and listing alternatives is how one action stays available to two roles
 * without inventing a third permission that means "either of those".
 *
 * This is a coarse gate for ROUTES. It is not where ownership is decided: the
 * 93 permissions in {@see Permissions::SCOPED} are granted but insufficient on
 * their own, and the policy behind the controller is what turns "students.view"
 * into "these students, because they are yours". A route that needed
 * `permission:` and nothing else would be a route whose controller trusts the
 * middleware to have done a job middleware cannot do — it has no idea which
 * student was asked for.
 */
final class EnsurePermission
{
    use RefusesUnauthorizedRequests;

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if ($permissions === []) {
            // Attached with no argument: `->middleware('permission:')`. Denying
            // would be safe but would look like a permission bug to whoever is
            // debugging it, and it is not — it is a route that was never written.
            abort(500, "The permission middleware guards [{$request->path()}] but names no permission.");
        }

        $user = $request->user();

        if ($user === null) {
            return $this->refuseUnauthenticated($request);
        }

        if (! $user instanceof Authorizable) {
            abort(500, 'The authenticated principal cannot be authorized: it does not implement '.Authorizable::class);
        }

        foreach ($permissions as $permission) {
            if (! Permissions::has($permission)) {
                // Fail loudly on programmer error. A typo here would otherwise
                // deny the route to everyone, forever, with no exception and no
                // log entry — indistinguishable from a permission that was
                // deliberately withheld. CI catches it on the first request.
                abort(500, "Route [{$request->path()}] is guarded by unregistered permission [{$permission}].");
            }

            if ($user->can($permission)) {
                return $next($request);
            }
        }

        return $this->refuseForbidden(
            $request,
            'auth.permission_denied',
            'You do not have permission to do that.',
        );
    }
}
