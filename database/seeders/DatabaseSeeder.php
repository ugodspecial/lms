<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds the database.
 *
 * NOTE: this class deliberately does NOT use `WithoutModelEvents`, which the
 * Laravel skeleton includes by default. That trait wraps seeding in
 * `Model::withoutEvents()`, so a `creating` hook never runs — and
 * `GeneratesUuid` assigns the NOT NULL `uuid` columns from exactly such a hook.
 * With events suppressed, seeding failed with "Field 'uuid' doesn't have a
 * default value".
 *
 * A column the database requires is not an event side-effect, so seeding must not
 * opt out of the code that populates it. If a later seeder needs to avoid an
 * observer — sending a notification for every seeded row, say — suppress events
 * around that seeder specifically rather than around all of them.
 *
 * Two categories of thing are seeded here, and they are kept strictly apart:
 *
 * • CONFIGURATION — permissions, roles, settings. Safe and necessary in every
 *   environment including production, because the application cannot authorize
 *   anything or read a business value without them. These seeders are
 *   idempotent: re-running syncs the code registry to the database rather than
 *   duplicating rows. Phase 1 adds PermissionSeeder, RoleSeeder and
 *   SettingSeeder to the first call() below.
 *
 * • DEMO DATA — logins somebody can actually use. Never seeded in production. A
 *   known email with a known password in a production `users` table is an
 *   administrator session for the price of a guess (§74), and `platform:doctor`
 *   fails a production deployment that has demo accounts enabled so this cannot
 *   slip through unnoticed.
 *
 * The skeleton's "Test User" is gone for the same reason: a hard-coded
 * `test@example.com` seeded in every environment is placeholder data in a
 * production database (§94).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Configuration. Safe in every environment, including production: the
            // application cannot authorize anything without these rows.
            PermissionSeeder::class,
            RoleSeeder::class,
            // Phase 1 also adds SettingSeeder once the settings inventory lands.
        ]);

        if (! app()->environment('production')) {
            $this->call([
                DemoUserSeeder::class,
            ]);
        }
    }
}
