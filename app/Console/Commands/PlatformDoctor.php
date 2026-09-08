<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\Permissions;
use App\Domain\Administration\Roles;
use App\Domain\Commerce\ValueObjects\Currency;
use App\Http\Middleware\EnsureIntegrationConfigured;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Deployment health check (§69, §75, Phase 0 exit gate).
 *
 * Shared cPanel hosting fails in boring, silent ways: a missing PHP extension, a
 * storage directory owned by the wrong user, a `.env` copied from the example
 * and never edited, `APP_DEBUG=true` left on in production. None of these break
 * the deploy; they break a parent's payment three days later.
 *
 * This command turns "is this deployment actually healthy?" into one command an
 * operator can run, and into a CI gate that fails the build. It reports facts it
 * observed, never a stored expectation — so it cannot drift out of date the way
 * a written checklist does.
 *
 * Exit code is non-zero if any check FAILED, which is what makes it usable as a
 * gate rather than as decoration.
 */
final class PlatformDoctor extends Command
{
    protected $signature = 'platform:doctor
                            {--json : Emit machine-readable results instead of a table}
                            {--skip-database : Do not attempt a database connection}
                            {--only= : Run a single check group (php, env, storage, database, runtime, assets, integrations)}';

    protected $description = 'Verify PHP, environment, storage, database, queue, assets and integration readiness for this deployment';

    /** @var list<array{group: string, label: string, status: 'pass'|'warn'|'fail', detail: string}> */
    private array $results = [];

    private string $group = 'general';

    public function handle(EnsureIntegrationConfigured $integrations): int
    {
        $only = $this->option('only');

        $groups = [
            'php' => fn () => $this->checkPhp(),
            'env' => fn () => $this->checkEnvironment(),
            'storage' => fn () => $this->checkStorage(),
            'database' => fn () => $this->checkDatabase(),
            'runtime' => fn () => $this->checkRuntime(),
            'assets' => fn () => $this->checkAssets(),
            'integrations' => fn () => $this->checkIntegrations($integrations),
        ];

        foreach ($groups as $name => $check) {
            if (is_string($only) && $only !== '' && $only !== $name) {
                continue;
            }

            $this->group = $name;
            $check();
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'summary' => $this->summary(),
                'checks' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode();
        }

        $this->renderTable();

        return $this->exitCode();
    }

    // ── PHP ─────────────────────────────────────────────────────────────────

    private function checkPhp(): void
    {
        $this->recordPass('PHP version', PHP_VERSION);

        // pdo_mysql is the whole application; mbstring and openssl are used by
        // auth and by every string operation on non-ASCII names, which in a
        // Nigerian student population is most of them.
        $required = [
            'pdo' => 'Database access',
            'pdo_mysql' => 'MySQL driver',
            'mbstring' => 'Multibyte strings (names in Yoruba, Igbo, Hausa)',
            'openssl' => 'Encryption, hashing, HTTPS',
            'json' => 'API and webhook payloads',
            'ctype' => 'Validation',
            'tokenizer' => 'Blade compilation',
            'xml' => 'XML handling',
            'curl' => 'Paystack, Zoom, Google API calls',
            'fileinfo' => 'Upload MIME detection — blocks a renamed .php upload',
            'bcmath' => 'Exact money arithmetic (ADR-02)',
            'zip' => 'Backup and bulk export downloads',
            'gd' => 'Certificate and thumbnail generation',
        ];

        foreach ($required as $extension => $reason) {
            extension_loaded($extension)
                ? $this->recordPass("ext-{$extension}", $reason)
                : $this->recordFail("ext-{$extension}", "Missing. Needed for: {$reason}. Enable it in cPanel → Select PHP Version.");
        }

        foreach (['intl', 'exif', 'imagick'] as $optional) {
            if (! extension_loaded($optional)) {
                $this->recordWarn("ext-{$optional}", 'Not installed. Optional, but some features will be reduced.');
            }
        }

        foreach ([
            'memory_limit' => ['256M', 'Composer, imports and PDF generation exceed 128M.'],
            'max_execution_time' => ['60', 'Bulk enrolment and report generation are slow on shared hosting.'],
            'upload_max_filesize' => ['20M', 'Course materials and tutor portfolios are larger than 2M.'],
            'post_max_size' => ['25M', 'Must exceed upload_max_filesize or large uploads fail silently.'],
        ] as $directive => [$minimum, $why]) {
            $actual = (string) ini_get($directive);
            $actualBytes = $this->toBytes($actual);
            $minimumBytes = $this->toBytes($minimum);

            // A time directive is in seconds, not bytes; -1 means unlimited.
            if ($directive === 'max_execution_time') {
                $ok = $actual === '-1' || $actual === '0' || (int) $actual >= (int) $minimum;
            } else {
                $ok = $actualBytes === -1 || $actualBytes >= $minimumBytes;
            }

            $ok
                ? $this->recordPass($directive, $actual)
                : $this->recordWarn($directive, "{$actual}, expected at least {$minimum}. {$why}");
        }
    }

    // ── Environment ─────────────────────────────────────────────────────────

    private function checkEnvironment(): void
    {
        if (! File::exists(base_path('.env'))) {
            $this->recordFail('.env', 'Missing. Copy .env.example to .env and fill in the values.');
        } else {
            $this->recordPass('.env', 'Present');
        }

        $key = (string) config('app.key');

        match (true) {
            $key === '' => $this->recordFail('APP_KEY', 'Empty. Run: php artisan key:generate'),
            str_starts_with($key, 'base64:dGVzdGluZ2tleXRoYXQ') => $this->recordFail(
                'APP_KEY',
                'Still the value from phpunit.xml. Run: php artisan key:generate'
            ),
            default => $this->recordPass('APP_KEY', 'Set'),
        };

        $env = app()->environment();
        $this->recordPass('APP_ENV', $env);

        $isProduction = $env === 'production';

        // The single most damaging misconfiguration on shared hosting: debug on
        // in production renders stack traces, database credentials and student
        // data to anyone who triggers an error (§76).
        $isProduction && config('app.debug')
            ? $this->recordFail('APP_DEBUG', 'true in production exposes stack traces, credentials and student data. Set APP_DEBUG=false.')
            : $this->recordPass('APP_DEBUG', var_export((bool) config('app.debug'), true));

        $url = (string) config('app.url');

        match (true) {
            $url === '' => $this->recordFail('APP_URL', 'Empty. Set it to the real public URL, or OAuth redirects and emails will be wrong.'),
            str_contains($url, 'localhost') && $isProduction => $this->recordFail(
                'APP_URL',
                "{$url} in production. OAuth callbacks, password-reset links and Paystack redirects all depend on it."
            ),
            default => $this->recordPass('APP_URL', $url),
        };

        // §74: demo accounts in production hand an attacker a real
        // administrator session for the price of a guess.
        if (config('platform.demo_accounts.enabled')) {
            $isProduction
                ? $this->recordFail('PLATFORM_DEMO_ACCOUNTS', 'Enabled in production. Set it to false.')
                : $this->recordWarn('PLATFORM_DEMO_ACCOUNTS', 'Enabled. Correct for local development only.');
        } else {
            $this->recordPass('PLATFORM_DEMO_ACCOUNTS', 'Disabled');
        }

        // §62 / ADR-08: storage timezone must remain UTC or every stored instant
        // becomes ambiguous across daylight-saving boundaries.
        (string) config('app.timezone') === 'UTC'
            ? $this->recordPass('app.timezone', 'UTC (storage timezone, correct)')
            : $this->recordFail('app.timezone', (string) config('app.timezone').' — must stay UTC. Display timezone is platform.timezone.default instead.');

        $displayTimezone = (string) config('platform.timezone.default');

        in_array($displayTimezone, \DateTimeZone::listIdentifiers(), true)
            ? $this->recordPass('platform.timezone.default', $displayTimezone)
            : $this->recordFail('platform.timezone.default', "{$displayTimezone} is not a valid IANA timezone.");

        $currency = (string) config('platform.currency');

        in_array($currency, Currency::available(), true)
            ? $this->recordPass('platform.currency', $currency)
            : $this->recordFail('platform.currency', "{$currency} is not defined in config/platform.php 'currencies'.");
    }

    // ── Storage ─────────────────────────────────────────────────────────────

    private function checkStorage(): void
    {
        // Writability is tested by actually writing, not by stat(). On cPanel a
        // directory can be 0775 and still be owned by a different user than the
        // PHP process, which is the failure that only a real write reveals.
        $directories = [
            'storage/app' => 'Uploaded files and exports',
            'storage/app/public' => 'Publicly served files',
            'storage/app/authenticated' => 'Login-required downloads (§59)',
            'storage/app/restricted' => 'Certificates and protected product files (§59)',
            'storage/framework/cache' => 'Compiled config, routes and cache',
            'storage/framework/sessions' => 'File sessions, if used',
            'storage/framework/views' => 'Compiled Blade templates',
            'storage/logs' => 'Application, payment and audit logs',
            'bootstrap/cache' => 'Cached services and packages',
        ];

        foreach ($directories as $relative => $purpose) {
            $path = base_path($relative);

            if (! File::isDirectory($path)) {
                $this->recordFail($relative, "Missing. Create it (purpose: {$purpose}).");

                continue;
            }

            $probe = $path.'/.doctor-probe';

            try {
                File::put($probe, (string) now()->timestamp);
                File::delete($probe);
                $this->recordPass($relative, "Writable — {$purpose}");
            } catch (Throwable $e) {
                $this->recordFail($relative, "Not writable by the PHP process ({$e->getMessage()}). Run: chown -R <cpanel user> {$relative} && chmod -R 775 {$relative}");
            }
        }

        // storage/app/public must be reachable from the document root, or every
        // "public" file 404s while appearing to upload successfully (§59).
        $link = public_path('storage');

        if (File::isDirectory($link) || File::exists($link)) {
            $this->recordPass('public/storage', 'Linked');
        } else {
            $this->recordWarn('public/storage', 'Not linked. Run: php artisan storage:link (required for public course thumbnails).');
        }

        // A log directory that cannot be pruned will eventually fill a shared
        // hosting quota and take the whole site down.
        $logFiles = File::glob(storage_path('logs/*.log')) ?: [];
        $totalMb = array_sum(array_map(
            fn (string $f): int => (int) (File::size($f) / 1_048_576),
            $logFiles
        ));

        $totalMb > 500
            ? $this->recordWarn('storage/logs', count($logFiles)." files, ~{$totalMb} MB. Prune old logs; shared hosting quotas are small.")
            : $this->recordPass('storage/logs', count($logFiles).' files, ~'.$totalMb.' MB');
    }

    // ── Database ────────────────────────────────────────────────────────────

    private function checkDatabase(): void
    {
        if ($this->option('skip-database')) {
            $this->recordWarn('database', 'Skipped (--skip-database)');

            return;
        }

        $connection = (string) config('database.default');

        // Reported once, as either a pass or a failure — emitting both would let a
        // caller reading the report find the pass first and miss the defect.
        if ($connection === 'sqlite') {
            $this->recordFail('DB_CONNECTION', 'sqlite is not supported: the schema uses FULLTEXT indexes and CHECK constraints (docs/04 §0).');

            return;
        }

        $this->recordPass('DB_CONNECTION', $connection);

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->recordFail('database connection', $this->safeMessage($e));

            return;
        }

        $this->recordPass('database connection', 'Connected');

        try {
            $serverVersion = DB::selectOne('SELECT VERSION() AS v');
            $version = (string) ($serverVersion->v ?? 'unknown');

            $this->recordPass('server version', $version);

            // MySQL 8 / MariaDB 10.6 minimum: the schema relies on generated
            // columns, CHECK constraint enforcement and utf8mb4 defaults.
            if (str_contains(strtolower($version), 'mariadb')) {
                $this->noteVersion($version, '10.6', 'MariaDB');
            } else {
                $this->noteVersion($version, '8.0', 'MySQL');
            }

            $collation = DB::selectOne('SELECT @@collation_database AS c');

            str_contains((string) ($collation->c ?? ''), 'utf8mb4')
                ? $this->recordPass('collation', (string) $collation->c)
                : $this->recordFail('collation', (string) ($collation->c ?? 'unknown').' — must be utf8mb4_* or accented names and Yoruba/Igbo diacritics will corrupt.');
        } catch (Throwable $e) {
            $this->recordWarn('server version', 'Could not read: '.$this->safeMessage($e));
        }

        try {
            if (! Schema::hasTable('migrations')) {
                $this->recordFail('migrations', 'No migrations table. Run: php artisan migrate --force');

                return;
            }

            $pending = $this->pendingMigrationCount();

            $pending > 0
                ? $this->recordFail('migrations', "{$pending} pending. Run: php artisan migrate --force")
                : $this->recordPass('migrations', 'Up to date');
        } catch (Throwable $e) {
            $this->recordFail('migrations', $this->safeMessage($e));

            return;
        }

        // Only reached once the schema is current: comparing the registry against
        // tables that do not exist yet would report a symptom of the pending
        // migration rather than the real problem.
        $this->checkAuthorizationRegistry();
    }

    /**
     * Does the authorization data in the database match the code registry?
     *
     * A deploy that runs `migrate --force` but forgets `db:seed --force` leaves
     * authorization silently out of step with the code, and it fails without
     * raising an error anywhere: a permission the registry declares and the table
     * lacks denies everybody, so a feature shipped that morning looks broken to
     * the administrators meant to use it. Neither that nor its mirror image is
     * visible in a log, so both are compared directly here.
     *
     * The two directions get different severities, because they are different
     * problems:
     *
     * • MISSING is a failure. The code needs it, the database does not have it,
     *   and the fix is a command the operator can run immediately.
     *
     * • ORPHAN is a warning. The database has a row the code no longer declares.
     *   That is worth knowing and is not safe to act on automatically: the row may
     *   be a deliberate retirement, or the registry that arrived may be the thing
     *   that is wrong. docs/05 §5 is explicit — orphans are reported, not removed
     *   — because a seeder that deletes on a bad registry would strip permissions
     *   from real staff as a side effect of a typo.
     */
    private function checkAuthorizationRegistry(): void
    {
        try {
            if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
                $this->recordWarn('authorization registry', 'permissions/roles tables absent. Run: php artisan migrate --force');

                return;
            }

            $declared = Permissions::all();
            $stored = $this->storedNames('permissions');

            $missing = array_values(array_diff($declared, $stored));
            $orphans = array_values(array_diff($stored, $declared));

            $missing === []
                ? $this->recordPass('permissions', count($declared).' registered, matching the code registry')
                : $this->recordFail('permissions', sprintf(
                    '%d of %d missing (%s). Run: php artisan db:seed --class=PermissionSeeder --force',
                    count($missing),
                    count($declared),
                    $this->summariseNames($missing),
                ));

            if ($orphans !== []) {
                $this->recordWarn('permission orphans', sprintf(
                    '%d in the database are not in the registry (%s). Left in place: retire one deliberately, or check that the deployed code is the code you meant to deploy.',
                    count($orphans),
                    $this->summariseNames($orphans),
                ));
            }

            $declaredRoles = Roles::ALL;
            $storedRoles = $this->storedNames('roles');

            $missingRoles = array_values(array_diff($declaredRoles, $storedRoles));
            $orphanRoles = array_values(array_diff($storedRoles, $declaredRoles));

            // Reported independently of the permissions result. Both go stale for
            // the same reason, and an operator reading one failure should not have
            // to fix it, re-run, and then discover the second.
            $missingRoles === []
                ? $this->recordPass('roles', count($declaredRoles).' seeded, matching the docs/05 matrix')
                : $this->recordFail('roles', sprintf(
                    '%d of %d missing (%s). Run: php artisan db:seed --class=RoleSeeder --force',
                    count($missingRoles),
                    count($declaredRoles),
                    $this->summariseNames($missingRoles),
                ));

            if ($orphanRoles !== []) {
                // Roles are few and named after real jobs, so naming them beats a
                // count: "3 unexpected" does not say whether somebody created a
                // role called Super Administrator.
                $this->recordWarn('role orphans', sprintf(
                    '%d in the database are not seeded roles (%s). Not removed: deleting a role takes it off everyone holding it.',
                    count($orphanRoles),
                    $this->summariseNames($orphanRoles),
                ));
            }
        } catch (Throwable $e) {
            $this->recordWarn('authorization registry', 'Could not read: '.$this->safeMessage($e));
        }
    }

    /**
     * @return list<string>
     */
    private function storedNames(string $table): array
    {
        return DB::table($table)
            ->orderBy('name')
            ->pluck('name')
            ->map(static fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * Names the first few offenders and says how many more there are.
     *
     * A count on its own does not tell an operator whether the missing permission
     * is the one gating the feature they just deployed, and listing all of them
     * would bury the answer in a 214-name wall. Five is enough to recognise the
     * shape of the problem — a whole group absent, or one stray row.
     *
     * @param  list<string>  $names
     */
    private function summariseNames(array $names, int $limit = 5): string
    {
        $shown = implode(', ', array_slice($names, 0, $limit));

        $remaining = count($names) - $limit;

        return $remaining > 0 ? "{$shown}, +{$remaining} more" : $shown;
    }

    // ── Runtime drivers ─────────────────────────────────────────────────────

    private function checkRuntime(): void
    {
        // §69 / ADR-14: these are the drivers shared hosting can actually run.
        // redis is fine if present, but must never be *required*.
        foreach ([
            'QUEUE_CONNECTION' => ['queue.default', ['database', 'sync']],
            'CACHE_STORE' => ['cache.default', ['database', 'file', 'array']],
            'SESSION_DRIVER' => ['session.driver', ['database', 'file']],
            'FILESYSTEM_DISK' => ['filesystems.default', ['local', 'public']],
        ] as $label => [$configKey, $sharedHostingFriendly]) {
            $value = (string) config($configKey);

            in_array($value, $sharedHostingFriendly, true)
                ? $this->recordPass($label, $value)
                : $this->recordWarn($label, "{$value} — works only if that service is actually available on this host.");
        }

        if ((string) config('queue.default') === 'database' && ! $this->option('skip-database')) {
            Schema::hasTable('jobs')
                ? $this->recordPass('jobs table', 'Present')
                : $this->recordFail('jobs table', 'Missing. Run: php artisan migrate --force');
        }

        // A database queue with no worker running is the most common cause of
        // "the platform accepted it but nothing happened".
        $scheduleFile = base_path('deploy/cpanel/crontab.txt');

        File::exists($scheduleFile)
            ? $this->recordPass('cron instructions', 'deploy/cpanel/crontab.txt present')
            : $this->recordWarn('cron instructions', 'deploy/cpanel/crontab.txt missing — see SETUP.md for the required cron entry.');

        // Without a worker, queued jobs (payment webhooks, emails, meeting
        // provisioning) accumulate forever and are never processed.
        try {
            $oldest = DB::table('jobs')->orderBy('available_at')->value('available_at');

            if ($oldest !== null && ((int) $oldest) < now()->subMinutes(15)->timestamp) {
                $this->recordFail('queue worker', 'Jobs have been waiting more than 15 minutes. Start a worker: php artisan queue:work --stop-when-empty (via cron).');
            } elseif ($oldest !== null) {
                $this->recordPass('queue worker', 'Jobs present but recent');
            } else {
                $this->recordPass('queue', 'Empty — nothing waiting');
            }
        } catch (Throwable) {
            // Table may not exist yet; checkDatabase already reported that.
        }

        $mailFrom = (string) config('mail.from.address');

        match (true) {
            $mailFrom === '' => $this->recordFail('MAIL_FROM_ADDRESS', 'Empty. Verification and password-reset emails cannot be sent.'),
            str_contains($mailFrom, 'example.test') && app()->environment('production') => $this->recordWarn(
                'MAIL_FROM_ADDRESS',
                "{$mailFrom} looks like a placeholder in production."
            ),
            default => $this->recordPass('MAIL_FROM_ADDRESS', $mailFrom),
        };

        $mailer = (string) config('mail.default');

        $mailer === 'log' && app()->environment('production')
            ? $this->recordFail('MAIL_MAILER', 'log in production means no email is ever delivered — verification and password reset silently do nothing.')
            : $this->recordPass('MAIL_MAILER', $mailer);
    }

    // ── Built assets ────────────────────────────────────────────────────────

    private function checkAssets(): void
    {
        // ADR-13: compiled assets are committed, because shared hosting has no
        // Node runtime. If they are missing every page renders unstyled.
        //
        // Only public/build/manifest.json counts. Illuminate\Foundation\Vite
        // resolves the manifest as public_path($buildDirectory.'/'.$filename) with
        // $filename defaulting to 'manifest.json', so a manifest anywhere else
        // makes every @vite directive throw ViteManifestNotFoundException — a
        // 500 on every page, not a missing stylesheet.
        //
        // Plain Vite 6+ defaults build.manifest to '.vite/manifest.json';
        // laravel-vite-plugin pins it to 'manifest.json'. Setting `manifest: true`
        // in vite.config.js discards the plugin's choice and reproduces that 500
        // exactly, so the misplaced-manifest case gets its own diagnosis rather
        // than the generic "missing" one.
        $canonical = public_path('build/manifest.json');

        if (! File::exists($canonical)) {
            File::exists(public_path('build/.vite/manifest.json'))
                ? $this->recordFail(
                    'public/build manifest',
                    'Vite wrote it to build/.vite/manifest.json but Laravel reads build/manifest.json — every page will 500. '.
                    'Remove `build.manifest` from vite.config.js so laravel-vite-plugin pins the filename, then `npm run build` and commit.'
                )
                : $this->recordFail(
                    'public/build manifest',
                    'Missing. Assets are committed to the repo (ADR-13) — run `npm run build` locally and commit, or restore them from git.'
                );

            return;
        }

        $this->recordPass('public/build manifest', str_replace(public_path('').'/', '', $canonical));

        try {
            /** @var array<string, array{file?: string}> $entries */
            $entries = json_decode((string) File::get($canonical), true, 512, JSON_THROW_ON_ERROR) ?? [];

            $missing = [];

            foreach ($entries as $entry) {
                if (isset($entry['file']) && ! File::exists(public_path('build/'.$entry['file']))) {
                    $missing[] = (string) $entry['file'];
                }
            }

            $missing === []
                ? $this->recordPass('built assets', count($entries).' manifest entries, all files present')
                : $this->recordFail('built assets', 'Manifest references missing files: '.implode(', ', $missing).'. Rebuild with `npm run build` and commit.');
        } catch (Throwable $e) {
            $this->recordFail('built assets', 'Manifest unreadable: '.$this->safeMessage($e));
        }

        File::exists(public_path('.htaccess'))
            ? $this->recordPass('public/.htaccess', 'Present')
            : $this->recordFail('public/.htaccess', 'Missing — pretty URLs will 404 on Apache shared hosting.');
    }

    // ── Integrations ────────────────────────────────────────────────────────

    private function checkIntegrations(EnsureIntegrationConfigured $integrations): void
    {
        /** @var array<string, array<string, mixed>> $configured */
        $configured = (array) config('platform.integrations', []);

        foreach ($configured as $name => $config) {
            $label = (string) ($config['label'] ?? $name);

            $missing = $integrations->missingRequirements($name);

            if ($missing === null) {
                $this->recordPass($name, "{$label} — configured");

                continue;
            }

            // Disabled on purpose is a valid state; the platform simply does not
            // render the entry point (§63). Enabled-but-incomplete is a defect.
            if ($missing['keys'] === []) {
                $this->recordWarn($name, "{$label} — disabled. See {$missing['docs']} to turn it on.");
            } else {
                $this->recordFail($name, "{$label} — enabled but missing: ".implode(', ', $missing['keys']).". See {$missing['docs']}.");
            }
        }

        // §41: the secret key must never be reachable from a rendered page. This
        // is asserted by a test too, but the operator should see it here.
        $secret = (string) config('services.paystack.secret');

        if ($secret !== '') {
            $public = (string) config('services.paystack.public');

            str_starts_with($secret, 'sk_')
                ? $this->recordPass('paystack secret', 'Present and correctly prefixed (never sent to a browser)')
                : $this->recordWarn('paystack secret', 'Present but does not start with sk_ — verify it is the secret key, not the public one.');

            if ($public !== '' && $public === $secret) {
                $this->recordFail('paystack keys', 'Public and secret keys are identical. One of them is wrong.');
            }
        }
    }

    // ── Reporting ───────────────────────────────────────────────────────────

    private function recordPass(string $label, string $detail): void
    {
        $this->results[] = ['group' => $this->group, 'label' => $label, 'status' => 'pass', 'detail' => $detail];
    }

    private function recordWarn(string $label, string $detail): void
    {
        $this->results[] = ['group' => $this->group, 'label' => $label, 'status' => 'warn', 'detail' => $detail];
    }

    private function recordFail(string $label, string $detail): void
    {
        $this->results[] = ['group' => $this->group, 'label' => $label, 'status' => 'fail', 'detail' => $detail];
    }

    /** @return array{pass: int, warn: int, fail: int} */
    private function summary(): array
    {
        return [
            'pass' => count(array_filter($this->results, fn (array $r): bool => $r['status'] === 'pass')),
            'warn' => count(array_filter($this->results, fn (array $r): bool => $r['status'] === 'warn')),
            'fail' => count(array_filter($this->results, fn (array $r): bool => $r['status'] === 'fail')),
        ];
    }

    private function exitCode(): int
    {
        // Warnings do not fail the build: a deployment without Zoom is a
        // legitimate choice. Failures do, because every one of them means a
        // feature will silently not work.
        return $this->summary()['fail'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function renderTable(): void
    {
        $this->newLine();

        // There is no `title` console component; the factory resolves
        // $this->components->x() to Illuminate\Console\View\Components\X::render()
        // and throws for anything it cannot find. twoColumnDetail is the closest
        // thing to a section heading and is already used for the group headers.
        //
        // The second argument must be an explicit '' rather than left to default
        // to null: the component's view guards on `$second !== ''` and then calls
        // htmlspecialchars() on it, so null reaches a non-nullable string
        // parameter and PHP emits a deprecation — which phpunit.xml promotes to a
        // test failure via failOnDeprecation.
        $this->components->twoColumnDetail('<fg=white;options=bold>PLATFORM HEALTH CHECK</>', '');

        $summary = $this->summary();

        $currentGroup = null;

        foreach ($this->results as $result) {
            if ($result['group'] !== $currentGroup) {
                $currentGroup = $result['group'];
                $this->newLine();
                $this->components->twoColumnDetail('<fg=white;options=bold>'.strtoupper($currentGroup).'</>', '');
            }

            $marker = match ($result['status']) {
                'pass' => '<fg=green>✔</>',
                'warn' => '<fg=yellow>▲</>',
                'fail' => '<fg=red>✖</>',
            };

            $this->line("  {$marker} <options=bold>{$result['label']}</>");
            $this->line("      <fg=gray>{$result['detail']}</>");
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            'Result',
            sprintf(
                '<fg=green>%d passed</>, <fg=yellow>%d warnings</>, <fg=red>%d failed</>',
                $summary['pass'],
                $summary['warn'],
                $summary['fail']
            )
        );

        if ($summary['fail'] > 0) {
            $this->components->error('This deployment is not healthy. Fix every ✖ above before pointing real users at it.');
        } elseif ($summary['warn'] > 0) {
            $this->components->warn('Healthy, with warnings worth reviewing.');
        } else {
            $this->components->info('All checks passed.');
        }

        $this->newLine();
    }

    private function noteVersion(string $version, string $minimum, string $flavour): void
    {
        if (preg_match('/(\d+\.\d+)/', $version, $m) && version_compare($m[1], $minimum, '<')) {
            $this->recordWarn('server version', "{$flavour} {$version} is below the {$minimum} minimum for this schema.");
        }
    }

    /**
     * Count migrations on disk that have not been run.
     *
     * Resolved through the `migration.repository` / `migrator` container bindings
     * rather than by class name: Laravel registers those two as string-keyed
     * singletons and does not alias the MigrationRepositoryInterface, so
     * `app(MigrationRepositoryInterface::class)` would throw a
     * BindingResolutionException — and a health check that crashes is worse than
     * no health check at all.
     */
    private function pendingMigrationCount(): int
    {
        /** @var MigrationRepositoryInterface $repository */
        $repository = app('migration.repository');

        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $files = $migrator->getMigrationFiles(database_path('migrations'));

        return count(array_diff(array_keys($files), $repository->getRan()));
    }

    /**
     * Convert an ini shorthand value ("256M") to bytes; -1 means unlimited.
     */
    private function toBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * A database or integration error message can contain credentials
     * ("Access denied for user 'eduplatform'@'localhost' (using password: YES)"
     * is benign, but a DSN with a password is not). Strip anything that looks
     * like a secret before it reaches the console or a CI log (§77).
     */
    private function safeMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        $message = preg_replace('/(password|pwd)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $message) ?? $message;
        $message = preg_replace('/\b(sk|pk)_(test|live)_[A-Za-z0-9]+/', '[REDACTED_KEY]', $message) ?? $message;

        return mb_substr($message, 0, 300);
    }
}
