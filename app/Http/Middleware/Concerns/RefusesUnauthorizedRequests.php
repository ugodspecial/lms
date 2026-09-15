<?php

declare(strict_types=1);

namespace App\Http\Middleware\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The refusal shape shared by the route gates.
 *
 * Both gates answer the same two questions — "who are you?" and "may you be
 * here?" — and they must answer them identically. If one returned a JSON error
 * body and the other an HTML page, an API client would need a different branch
 * per endpoint, and §42's promise of machine-readable error codes would hold on
 * some routes and not others. Keeping the format in one place is what makes that
 * promise a property of the platform rather than of each middleware author.
 *
 * Nothing here decides WHO is refused. Each gate makes its own decision and calls
 * these to express it.
 */
trait RefusesUnauthorizedRequests
{
    /**
     * Nobody is authenticated. 401, not 403: the caller may well be allowed here
     * once they identify themselves, and conflating the two is how an API client
     * ends up retrying a request that could never succeed.
     */
    protected function refuseUnauthenticated(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'auth.unauthenticated',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        // `login` is registered by Fortify in Phase 1; fall back rather than
        // turning a redirect into a RouteNotFoundException in a guest's face.
        return redirect()->guest(
            Route::has('login') ? route('login') : route('home')
        );
    }

    /**
     * Authenticated, and not allowed. The message names the area at most — never
     * the permission or role that would have worked, because telling a caller what
     * they are missing turns a 403 into a map of the privilege model (§76).
     */
    protected function refuseForbidden(Request $request, string $code, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => ['code' => $code, 'message' => $message],
            ], 403);
        }

        abort(403, $message);
    }
}
