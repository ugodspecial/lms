<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Administration\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs {@see Permissions} into the `permissions` table.
 *
 * Code is the source of truth for *what exists*; the database is the source of
 * truth for *who has what* (ADR-09). This seeder makes the second mirror the
 * first, in one direction only: it creates permissions the registry declares and
 * the table lacks.
 *
 * It never deletes, per docs/05 §5 — "never deletes ones in use; orphans are
 * reported, not removed". Deleting is the wrong default here because the failure
 * it protects against is small and the failure it causes is large. A permission
 * retired from the registry but left in the table is inert: no policy asks for
 * it. A permission *missing* from the registry is not inert — if a bad merge
 * dropped one group constant, a destructive seeder would delete those 26 rows
 * during a routine deploy and spatie's cascade would strip them from every
 * Finance Officer holding them, turning a typo in a const array into a company
 * that cannot see its own invoices. Retiring a permission on purpose is rare,
 * deliberate, and worth a one-off command; surviving an accidental omission is
 * worth more.
 *
 * Orphans are surfaced rather than ignored: `platform:doctor` reports them as a
 * warning, so a retired permission is visible to whoever retires it without the
 * seeder being able to act on a registry it cannot trust.
 *
 * Idempotent, and safe to run in production on every deploy: a run following a
 * deploy that changed no permission performs no writes and touches no cache.
 *
 * Silent by design. Whether the table ended up matching the registry is reported
 * by `platform:doctor`, which is where an operator actually looks — and which
 * fails a deploy that migrated without seeding.
 */
final class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guard = (string) config('permission.default_guard_name', 'web');

        $declared = Permissions::all();

        $existing = Permission::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        $created = 0;

        foreach (array_values(array_diff($declared, $existing)) as $name) {
            Permission::create(['name' => $name, 'guard_name' => $guard]);

            $created++;
        }

        if ($created > 0) {
            // spatie caches the permission list. Without this the seeder's own
            // writes are invisible to the authorization checks that follow it in
            // the same request, which is how a deploy ends up looking like it
            // worked and then denying everything.
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
