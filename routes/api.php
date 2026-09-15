<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API routes — v1
|--------------------------------------------------------------------------
|
| Prefix /api (configured in bootstrap/app.php), Sanctum token auth, stateless.
|
| The API is a first-class surface, not an afterthought: it carries the SAME
| authorization checks as the web UI, because both go through the same policies
| and domain services (ADR-14, §42). A route that only exists in the API can
| become the easy way to bypass a rule.
|
| Every future endpoint obeys this contract:
|
|   • Versioned prefix:  /api/v1/...
|   • Errors:            { "error": { "code", "message", "fields" } }
|                        (App\Exceptions\PlatformException::toApiResponse)
|   • Money:             integer minor units + ISO currency code, never floats
|   • Time:              ISO-8601 UTC; the client converts for display
|   • Lists:             ?page=&per_page= with meta + links
|   • Mutations:         idempotency key where a retry must not duplicate
|
| Business endpoints arrive with the phase that makes them real. A stub that
| returns fake data would be indistinguishable from a working one to a client
| developer, so there are none (§63).
|
*/

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Unauthenticated capability probe: confirms the API is deployed and which
    // version it is, without needing a token.
    Route::get('/status', fn () => [
        'status' => 'ok',
        'api' => 'v1',
        'time' => now()->toIso8601String(),
    ])->name('api.v1.status');

    // Sanity check for an issued token. Returns only the caller's own identity —
    // never another user's data (§64).
    Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
        $user = $request->user();

        return [
            'data' => [
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name,
                'email' => $user->email,
            ],
        ];
    })->name('api.v1.me');
});
