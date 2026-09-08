<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `platform:doctor` is the Phase 0 exit gate and the first thing an operator runs
 * on a new shared-hosting deploy (docs/10, deploy/cpanel/crontab.txt).
 *
 * These tests assert that it *reports*, not that it *passes*. Whether bcmath or
 * gd is installed is a property of the host rather than of the code, so a hard
 * assertion on the exit code would fail CI for reasons unrelated to a commit.
 * What must be guaranteed is that the command runs, covers every required
 * category, gates on a real defect, and cannot itself leak a credential into a
 * CI log or a cron email.
 */
final class PlatformDoctorTest extends TestCase
{
    /** @var array{summary: array{pass: int, warn: int, fail: int}, checks: list<array{group: string, label: string, status: string, detail: string}>}|null */
    private ?array $cachedReport = null;

    public function test_it_runs_and_emits_a_machine_readable_report(): void
    {
        $exitCode = $this->doctor();

        // 0 = healthy, 1 = at least one failure. Any other code means the command
        // itself crashed, which is the one outcome that must never be tolerated.
        $this->assertContains($exitCode, [0, 1], 'platform:doctor exited with an unexpected code');

        $report = $this->report();

        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('checks', $report);

        foreach (['pass', 'warn', 'fail'] as $key) {
            $this->assertArrayHasKey($key, $report['summary']);
            $this->assertIsInt($report['summary'][$key]);
        }

        $this->assertNotEmpty($report['checks']);

        $this->assertSame(
            count($report['checks']),
            $report['summary']['pass'] + $report['summary']['warn'] + $report['summary']['fail'],
            'Every check must be accounted for in exactly one summary bucket'
        );

        foreach ($report['checks'] as $check) {
            $this->assertArrayHasKey('group', $check);
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('detail', $check);
            $this->assertContains($check['status'], ['pass', 'warn', 'fail']);
            $this->assertNotEmpty($check['label']);
        }
    }

    public function test_it_reports_the_php_version_and_required_extensions(): void
    {
        $labels = $this->labels();

        $this->assertContains('PHP version', $labels);
        $this->assertSame(PHP_VERSION, $this->detailFor('PHP version'));

        // Each of these backs a feature that must work on shared hosting.
        // `fileinfo` in particular is the control that stops a renamed .php file
        // from being accepted as a course upload (§57); `bcmath` is what makes
        // money arithmetic exact (ADR-02).
        foreach ([
            'ext-pdo', 'ext-pdo_mysql', 'ext-mbstring', 'ext-openssl',
            'ext-json', 'ext-curl', 'ext-fileinfo', 'ext-bcmath', 'ext-zip', 'ext-gd',
        ] as $extension) {
            $this->assertContains($extension, $labels, "platform:doctor did not check {$extension}");
        }
    }

    public function test_it_reports_storage_writability_for_every_file_tier(): void
    {
        $labels = $this->labels();

        // An unwritable directory is the most common shared-hosting failure, and
        // it fails silently: an upload appears to succeed and then 404s (§59).
        foreach ([
            'storage/app',
            'storage/app/public',
            'storage/app/authenticated',
            'storage/app/restricted',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ] as $directory) {
            $this->assertContains($directory, $labels, "platform:doctor did not check writability of {$directory}");

            // Writability is proven by writing, so in CI these must all pass —
            // a failure here means the repository shipped an unwritable path.
            $this->assertSame('pass', $this->statusFor($directory), "{$directory} is not writable");
        }
    }

    public function test_it_leaves_no_probe_file_behind(): void
    {
        $this->doctor();

        $this->assertFalse(
            file_exists(storage_path('app/.doctor-probe')),
            'The writability probe must clean up after itself'
        );
        $this->assertFalse(file_exists(storage_path('logs/.doctor-probe')));
    }

    public function test_it_reports_database_connectivity_when_not_skipped(): void
    {
        $labels = $this->labels(skipDatabase: false);

        $this->assertContains('DB_CONNECTION', $labels);
        $this->assertContains('database connection', $labels);
    }

    public function test_it_reports_that_sqlite_is_not_a_supported_database(): void
    {
        // docs/04 §0: the schema uses FULLTEXT indexes and enforced CHECK
        // constraints. SQLite silently ignores both, so a SQLite deploy would
        // appear to work while enforcing none of the integrity rules.
        config(['database.default' => 'sqlite']);

        $this->doctor(skipDatabase: false);

        $this->assertSame('fail', $this->statusFor('DB_CONNECTION'));
        $this->assertStringContainsString('not supported', $this->detailFor('DB_CONNECTION'));
    }

    public function test_it_reports_the_state_of_every_configured_integration(): void
    {
        $labels = $this->labels();

        foreach (array_keys((array) config('platform.integrations')) as $integration) {
            $this->assertContains($integration, $labels, "platform:doctor did not report on {$integration}");
        }
    }

    public function test_a_disabled_integration_is_a_warning_not_a_failure(): void
    {
        // Not running Zoom is a legitimate deployment choice and must not fail a
        // deploy. Enabled-but-missing-credentials is the actual defect (§63).
        config(['platform.integrations.zoom.enabled' => false]);

        $this->doctor();

        $this->assertSame('warn', $this->statusFor('zoom'));
    }

    public function test_an_enabled_integration_with_missing_credentials_fails(): void
    {
        config([
            'platform.integrations.zoom.enabled' => true,
            'services.zoom.client_id' => '',
            'services.zoom.client_secret' => '',
        ]);

        $this->doctor();

        $this->assertSame('fail', $this->statusFor('zoom'));
        $this->assertStringContainsString('services.zoom.client_id', $this->detailFor('zoom'));
    }

    public function test_credential_placeholders_are_treated_as_unconfigured(): void
    {
        // Copying .env.example verbatim is the most likely first deploy. A key
        // that is present but still a placeholder must not be reported as working,
        // or the platform will render a Paystack button that cannot pay.
        config([
            'platform.integrations.paystack.enabled' => true,
            'services.paystack.secret' => 'sk_test_replace_me',
            'services.paystack.public' => 'pk_test_replace_me',
        ]);

        $this->doctor();

        $this->assertSame('fail', $this->statusFor('paystack'));
    }

    public function test_it_fails_when_the_storage_timezone_is_not_utc(): void
    {
        // ADR-08: storage must stay UTC. Changing app.timezone makes every stored
        // instant ambiguous across a daylight-saving boundary.
        config(['app.timezone' => 'Africa/Lagos']);

        $this->doctor();

        $this->assertSame('fail', $this->statusFor('app.timezone'));
    }

    public function test_it_exits_non_zero_when_a_check_fails(): void
    {
        config(['app.timezone' => 'Africa/Lagos']);

        $this->assertSame(1, $this->doctor(), 'A failed check must make the command gate the deploy');
    }

    public function test_it_never_writes_a_secret_into_its_own_output(): void
    {
        // The doctor runs from cron and mails its output. A diagnostic that prints
        // the Paystack secret key would put a live credential into an inbox and
        // into a log file that survives far longer than the key (§41, §77).
        config([
            'platform.integrations.paystack.enabled' => true,
            'services.paystack.secret' => 'sk_test_SUPER_SECRET_VALUE_9876543210',
            'services.paystack.public' => 'pk_test_public_value_9876543210',
        ]);

        Artisan::call('platform:doctor', ['--json' => true, '--skip-database' => true]);
        $json = Artisan::output();

        Artisan::call('platform:doctor', ['--skip-database' => true]);
        $table = Artisan::output();

        foreach ([$json, $table] as $output) {
            $this->assertStringNotContainsString('SUPER_SECRET_VALUE', $output);
            $this->assertStringNotContainsString('sk_test_SUPER', $output);
            $this->assertStringNotContainsString('9876543210', $output);
        }

        // Naming the integration is fine and necessary — it is what tells the
        // operator which one to go and configure.
        $this->assertStringContainsString('paystack', $json);
    }

    public function test_it_can_be_scoped_to_a_single_group(): void
    {
        Artisan::call('platform:doctor', ['--json' => true, '--only' => 'php']);

        $checks = json_decode(trim(Artisan::output()), true)['checks'];

        $this->assertNotEmpty($checks);
        $this->assertSame(['php'], array_values(array_unique(array_column($checks, 'group'))));
    }

    public function test_the_human_readable_output_is_not_empty(): void
    {
        Artisan::call('platform:doctor', ['--skip-database' => true]);

        $output = Artisan::output();

        $this->assertStringContainsString('PLATFORM HEALTH CHECK', strtoupper($output));
        $this->assertStringContainsString('Result', $output);
    }

    public function test_it_fails_when_the_vite_manifest_is_not_where_laravel_reads_it(): void
    {
        $canonical = public_path('build/manifest.json');
        $misplaced = public_path('build/.vite/manifest.json');

        $this->assertFileExists(
            $canonical,
            'Assets are committed to the repo (ADR-13), so the build manifest must exist in a fresh clone.'
        );

        $backup = (string) file_get_contents($canonical);

        try {
            // The failure this guards against is silent in the build and total at
            // runtime. Setting `build.manifest: true` in vite.config.js discards
            // the filename laravel-vite-plugin pins, Vite 6+ then writes
            // .vite/manifest.json, and Illuminate\Foundation\Vite keeps looking
            // in build/manifest.json — so every @vite directive throws
            // ViteManifestNotFoundException and every page returns a 500.
            //
            // A build that succeeds and a site that is entirely down is exactly
            // the case a deployment health check exists to catch.
            @mkdir(dirname($misplaced), 0775, true);
            file_put_contents($misplaced, $backup);
            unlink($canonical);

            $this->doctor();

            $this->assertSame(
                'fail',
                $this->statusFor('public/build manifest'),
                'A manifest Laravel cannot read must be reported as a failure, not a pass or a warning.'
            );

            // The detail must name the fix. "Missing" alone sends an operator
            // looking for a build that succeeded ten minutes ago.
            $detail = $this->detailFor('public/build manifest');
            $this->assertStringContainsString('.vite/manifest.json', $detail);
            $this->assertStringContainsString('vite.config.js', $detail);
        } finally {
            // public/build is committed, so it is shared state across the whole
            // run: restore it whatever the assertions did, or every later test
            // inherits a broken build directory and fails for the wrong reason.
            @unlink($misplaced);
            @rmdir(dirname($misplaced));
            file_put_contents($canonical, $backup);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function doctor(bool $skipDatabase = true): int
    {
        // A new run invalidates whatever the previous one produced.
        $this->cachedReport = null;

        return Artisan::call('platform:doctor', [
            '--json' => true,
            '--skip-database' => $skipDatabase,
        ]);
    }

    /**
     * The parsed report of the most recent platform:doctor run.
     *
     * Artisan::output() is DESTRUCTIVE: it returns BufferedOutput::fetch(), which
     * hands back the buffer and then sets it to ''. A second call in the same test
     * therefore returns an empty string, json_decode turns that into null, and the
     * test fails on the assertion after the first one — which is why the tests that
     * asked for two details failed while the ones that asked for one passed.
     *
     * Parse once per run and reuse it.
     *
     * @return array{summary: array{pass: int, warn: int, fail: int}, checks: list<array{group: string, label: string, status: string, detail: string}>}
     */
    private function report(): array
    {
        if ($this->cachedReport !== null) {
            return $this->cachedReport;
        }

        $output = trim(Artisan::output());

        /** @var array{summary: array{pass: int, warn: int, fail: int}, checks: list<array{group: string, label: string, status: string, detail: string}>} $report */
        $report = json_decode($output, true);

        $this->assertIsArray($report, "platform:doctor --json did not emit parseable JSON:\n{$output}");

        return $this->cachedReport = $report;
    }

    private function labels(bool $skipDatabase = true): array
    {
        $this->doctor($skipDatabase);

        return array_column($this->report()['checks'], 'label');
    }

    private function statusFor(string $label): string
    {
        return (string) ($this->checkFor($label)['status'] ?? '');
    }

    private function detailFor(string $label): string
    {
        return (string) ($this->checkFor($label)['detail'] ?? '');
    }

    /**
     * @return array{group: string, label: string, status: string, detail: string}
     */
    private function checkFor(string $label): array
    {
        foreach ($this->report()['checks'] as $check) {
            if ($check['label'] === $label) {
                return $check;
            }
        }

        $this->fail("platform:doctor reported no check labelled [{$label}].");
    }
}
