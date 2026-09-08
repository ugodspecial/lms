<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Administration\Permissions;
use App\Domain\Administration\Roles;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Authorization wiring (ADR-09, docs/05 §5).
 *
 * Two jobs, both of which have to be in `Gate::before` and nowhere else:
 *
 * 1. Super Admin gets a bypass. This is the ONLY place a role name appears in
 *    authorization logic. Everywhere else the code asks for a permission, so a
 *    role can be renamed, reassigned or granted to the wrong person without
 *    changing what any check means.
 *
 * 2. Registered permission strings become abilities. Without this, Laravel's
 *    Gate falls back to denying any ability it has no definition for, so
 *    `$user->can('students.view')` would return false for everybody who is not a
 *    Super Admin — including every Finance Officer holding that exact permission.
 *    Nothing would throw; every authorization check would simply say no. That is
 *    why the mapping lives here rather than being assumed.
 *
 * Both return `null` rather than `false` whenever they decline to decide. `false`
 * from a before callback is a veto that stops the policies from running at all,
 * and the whole point of the fallthrough is that a model-level check —
 * `$user->can('view', $student)` — must still get its turn.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::before(function (Authenticatable $user, string $ability): ?bool {
            // Typed as Authenticatable and narrowed here rather than typed as User:
            // a before callback that cannot be called with the current principal
            // must decline, not raise a TypeError during an unrelated check.
            if (! $user instanceof User) {
                return null;
            }

            if ($user->hasRole(Roles::SUPER_ADMIN)) {
                // Total for administration, and it stops at participation.
                // reviews.create and reviews.respond record that somebody attended
                // something; docs/05 §3.11 denies them to Super Admin and
                // Administrator alike, because a bypass that reached them would let
                // an administrator post a testimonial about a session they were not
                // at — the harm §88's "cannot review self" exists to prevent.
                return in_array($ability, Roles::PARTICIPANT_ONLY, true) ? null : true;
            }

            // Only strings the registry declares are treated as permissions. A
            // policy ability such as 'view' or 'update' must fall through to the
            // policy that implements it, and this is what keeps the two namespaces
            // from colliding.
            if (! Permissions::has($ability)) {
                return null;
            }

            try {
                return $user->hasPermissionTo($ability) ? true : null;
            } catch (PermissionDoesNotExist) {
                // The registry declares it, the database does not have it: a deploy
                // that migrated without seeding. Deny rather than throw — a missing
                // permission row must not take the whole site down with it, and
                // `platform:doctor` reports the mismatch as a failure.
                return null;
            }
        });
    }
}
