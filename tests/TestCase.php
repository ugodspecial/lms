<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the database when the suite migrates.
     *
     * This lives here rather than on the classes that need it, because of how
     * `RefreshDatabase` actually works — and the mechanism is easy to break without
     * meaning to:
     *
     *   - `migrate:fresh` runs ONCE per process, the first time any test uses the
     *     trait. `RefreshDatabaseState::$migrated` then makes every later class
     *     skip it and go straight to opening a transaction.
     *   - The `--seed` flag for that single run is read off `$this` by
     *     `CanConfigureMigrationCommands::shouldSeed()` — that is, off whichever
     *     class happened to run FIRST, not off the suite.
     *
     * So a `$seed = true` declared only on the tests that need the permission
     * registry is really a bet on alphabetical order. It held until a Feature class
     * named `AdministrationPersistenceTest` sorted ahead of
     * `AuthorizationRegistrySeedingTest`: `migrate:fresh` then ran with
     * `--seed=false`, nothing seeded the registry for ANY class, and 21 tests
     * across two suites failed with "there is no role named `Super Admin`" — none of
     * them anywhere near the file that caused it.
     *
     * Declaring it once here removes the bet. Whichever class runs first seeds, and
     * because `migrate:fresh --seed` happens outside the per-test transaction the
     * registry is present for the whole run — which is also what production looks
     * like, so tests exercise the authorization path against real data rather than
     * an empty table. A class that wants an unseeded database says so with
     * `protected $seed = false;`.
     *
     * The cost is that no test may assume an empty table. Assertions are written
     * against the records a test creates, or scoped to them, never against a global
     * count.
     *
     * @var bool
     */
    protected $seed = true;
}
