<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Administration\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs the eleven roles of docs/05 §2, and each one's permission bundle, from
 * {@see Roles} into the database.
 *
 * Runs after PermissionSeeder: `syncPermissions()` resolves each name to a
 * permission row and throws if it is missing, so the rows have to exist first.
 *
 * Two deliberate limits on what syncing means:
 *
 * • A role's BUNDLE always equals the matrix. Granting a permission by hand in
 *   the database is undone by the next deploy. That is the point — the matrix is
 *   reviewed, the ad-hoc grant is not. Editing a bundle is also reversible and
 *   affects no identity: it changes what a role can do, not who holds it.
 *
 * • A role ROW is never deleted, for the same reason PermissionSeeder does not
 *   delete: docs/05 §5 reports orphans rather than removing them. Deleting a role
 *   cascades to `model_has_roles` and takes it off every person holding it, so a
 *   registry that arrives incomplete — one constant dropped in a merge — would
 *   quietly demote real staff during a routine deploy. An unexpected role in the
 *   table is reported by `platform:doctor` and left for a human to decide about.
 *
 * Super Admin is seeded with the 211 permissions the matrix grants it — not all
 * 214, and not none. Its authority comes from `Gate::before` (ADR-09) rather
 * than from a bundle that must be updated every time a permission is added; the
 * bundle is seeded anyway so the admin UI shows the real matrix and so a
 * misconfigured bypass degrades to a very powerful administrator instead of to
 * nothing.
 *
 * The three it lacks are each deliberate. `reviews.create` and
 * `reviews.respond` are listed in {@see Roles::PARTICIPANT_ONLY}: the bypass
 * must not reach them. `certificates.verify_public` is in
 * {@see Permissions::PUBLIC_CAPABILITIES} and belongs to no bundle at all,
 * because it guards an unauthenticated, rate-limited endpoint that no logged-in
 * role is involved in.
 *
 * Silent by design; `platform:doctor` reports whether the database matches.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $guard = (string) config('permission.default_guard_name', 'web');

        $created = 0;

        foreach (Roles::ALL as $name) {
            $existing = Role::query()
                ->where('name', $name)
                ->where('guard_name', $guard)
                ->exists();

            $role = Role::findOrCreate($name, $guard);

            $role->syncPermissions(Roles::permissionsFor($name));

            if (! $existing) {
                $created++;
            }
        }

        if ($created > 0) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
