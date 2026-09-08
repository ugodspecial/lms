<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds the database.
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
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            // Phase 1: PermissionSeeder::class, RoleSeeder::class, SettingSeeder::class,
        ]);

        if (! app()->environment('production')) {
            $this->call([
                DemoUserSeeder::class,
            ]);
        }
    }
}
