<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Administration;

use App\Domain\Administration\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A settings read must never be the reason a page fails (§60, docs/01 §5.3).
 *
 * The platform reads settings from Blade templates, from money and timezone
 * helpers, and from `platform:doctor` — all of which can run against a database
 * with no schema in it yet: a fresh install, a deploy that migrated halfway, or a
 * connection whose user lost the privilege on one table. If `setting()` threw
 * there, the landing page would be a 500 and the installer could not tell the
 * operator what was wrong, because the thing that reports the problem would be
 * the thing that broke.
 *
 * So the contract is: callers get their default, and the failure is never cached —
 * an hour of defaults after the migration that fixes it has already run would be
 * worse than the outage, because it would look like the setting had been ignored.
 *
 * There is no database fixture here, and that is deliberate. Dropping `settings`
 * is DDL, MySQL commits implicitly on DDL, and that would silently end the
 * enclosing test transaction and leak every row the suite had written so far into
 * the shared test database. Pointing the connection at a database that does not
 * exist produces the same QueryException without touching a single table.
 */
final class SettingsDegradationTest extends TestCase
{
    public function test_an_unreadable_settings_table_yields_the_callers_default(): void
    {
        $this->pointTheConnectionAtAMissingDatabase();

        $settings = app(SettingsService::class);

        $this->assertSame([], $settings->all());
        $this->assertFalse($settings->isReadable(), 'The service has to be able to say "we could not ask", not just "nobody set this".');

        // The documented call pattern: the default comes from config, so the value
        // lives in exactly one place when the table has no row for it yet.
        $this->assertSame(
            config('platform.currency'),
            $settings->get('platform.currency', config('platform.currency')),
        );
    }

    public function test_a_failed_read_is_not_cached(): void
    {
        $this->pointTheConnectionAtAMissingDatabase();

        $settings = app(SettingsService::class);
        $settings->all();
        $settings->all();

        $this->assertFalse(
            Cache::has(SettingsService::CACHE_KEY),
            'A failure must not be cached: the next read has to try again, or a migrated database keeps serving defaults for the whole TTL.',
        );
    }

    public function test_the_setting_helper_degrades_the_same_way(): void
    {
        $this->pointTheConnectionAtAMissingDatabase();

        // This is the call a Blade file makes, and it is the one that has to keep
        // rendering during an install rather than throw.
        $this->assertSame(18, setting('platform.age_of_majority', 18));
        $this->assertSame('NGN', setting('platform.currency', 'NGN'));
        $this->assertNull(setting('platform.currency'));
    }

    private function pointTheConnectionAtAMissingDatabase(): void
    {
        // Every test method gets a fresh application instance (Laravel's
        // RefreshApplicationTrait rebuilds it in setUp), so mutating connection
        // config here cannot leak into another test, and nothing is written
        // anywhere so there is no transaction to disturb.
        Cache::forget(SettingsService::CACHE_KEY);

        config(['database.connections.mysql.database' => 'eduplatform_test_absent']);
        DB::purge('mysql');
    }
}
