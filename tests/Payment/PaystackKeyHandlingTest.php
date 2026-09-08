<?php

declare(strict_types=1);

namespace Tests\Payment;

use Tests\TestCase;

/**
 * Paystack key handling (§41, §72, §75).
 *
 * The single hardest constraint on the commerce module is that the **secret** key
 * must never reach a browser, a compiled asset, a log line or a repository —
 * while the **public** key must. Getting that backwards, or leaking the secret
 * into a Blade view during a later phase, is not a style problem: it is anyone
 * being able to charge and refund the merchant account.
 *
 * These tests run in Phase 0, before any payment code exists, precisely so the
 * boundary is enforced from the first commit rather than retro-fitted in Phase 8.
 *
 * §72: no test here uses a real credential. Every value is a fake from
 * .env.testing or a synthetic string generated inside the test.
 */
final class PaystackKeyHandlingTest extends TestCase
{
    /** Matches a real-looking Paystack key: a prefix, a mode, then 20+ chars. */
    private const KEY_PATTERN = '/\b(?:sk|pk)_(?:test|live)_[A-Za-z0-9]{20,}\b/';

    public function test_the_active_key_pair_follows_the_configured_mode(): void
    {
        $mode = (string) config('services.paystack.mode');

        // §75: mode is independent of APP_ENV, so it must be read from
        // configuration and never inferred from the environment name.
        $this->assertContains($mode, ['test', 'live']);

        $this->assertSame(
            config("services.paystack.{$mode}.secret"),
            config('services.paystack.secret'),
            'The active secret key must be the one belonging to the configured mode'
        );
        $this->assertSame(
            config("services.paystack.{$mode}.public"),
            config('services.paystack.public'),
            'The active public key must be the one belonging to the configured mode'
        );

        // Both pairs stay readable so an administrator can see which mode is in
        // use, and so switching modes needs no re-entry of credentials.
        $this->assertArrayHasKey('test', (array) config('services.paystack'));
        $this->assertArrayHasKey('live', (array) config('services.paystack'));

        $other = $mode === 'live' ? 'test' : 'live';

        if (config("services.paystack.{$other}.secret")) {
            $this->assertNotSame(
                config("services.paystack.{$other}.secret"),
                config('services.paystack.secret'),
                'The active secret must not be the other mode\'s secret'
            );
        }

        // Under .env.testing the mode is `test` with a fake key, so the active
        // secret must be non-empty — proving the resolution actually selected a
        // pair rather than silently yielding null.
        $this->assertSame('test', $mode);
        $this->assertNotEmpty((string) config('services.paystack.secret'));
    }

    public function test_the_signature_scheme_is_declared_not_assumed(): void
    {
        // Paystack signs the RAW body with HMAC-SHA512 using the secret key. It
        // issues no separate webhook secret. If either of these values drifted,
        // verification would fail on every payload containing a nested object
        // while looking correctly implemented (§41).
        $this->assertSame('x-paystack-signature', (string) config('services.paystack.signature_header'));
        $this->assertSame('sha512', (string) config('services.paystack.signature_algorithm'));
        $this->assertStringStartsWith('https://', (string) config('services.paystack.base_url'));
    }

    public function test_no_secret_key_pattern_appears_in_any_built_asset(): void
    {
        $buildPath = public_path('build');

        // ADR-13: compiled assets are committed, because shared hosting has no
        // Node runtime. That also means a key baked into a bundle at build time
        // would be committed to the repository and served to every visitor.
        $this->assertDirectoryExists($buildPath, 'public/build must exist — assets are committed per ADR-13');

        $files = $this->filesUnder($buildPath, ['js', 'css', 'json', 'map']);

        $this->assertNotEmpty($files, 'public/build contains no compiled assets');

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                self::KEY_PATTERN,
                $contents,
                'A Paystack key is baked into a built asset: '.basename($file)
            );

            $secret = (string) config('services.paystack.secret');

            if ($secret !== '') {
                $this->assertStringNotContainsString(
                    $secret,
                    $contents,
                    'The configured Paystack secret appears in a built asset: '.basename($file)
                );
            }
        }
    }

    public function test_no_live_key_is_committed_anywhere_in_the_source_tree(): void
    {
        // Zero tolerance for a live key in tracked source. A test key in a
        // repository is a nuisance; a live key is a compromised merchant account.
        $offenders = [];

        foreach (['app', 'config', 'resources', 'routes', 'database', 'tests', 'bootstrap', 'deploy'] as $directory) {
            foreach ($this->filesUnder(base_path($directory), ['php', 'js', 'css', 'json', 'md', 'txt', 'yml', 'yaml', 'xml']) as $file) {
                $contents = (string) file_get_contents($file);

                if (preg_match_all('/\b(?:sk|pk)_live_[A-Za-z0-9]{10,}\b/', $contents, $matches) > 0) {
                    $offenders[] = str_replace(base_path().'/', '', $file).' → '.implode(', ', $matches[0]);
                }
            }
        }

        $this->assertSame([], $offenders, "Live Paystack keys found in source:\n".implode("\n", $offenders));
    }

    public function test_the_example_environment_ships_obviously_fake_keys(): void
    {
        // .env.example is copied verbatim on a first deploy. Its keys must be
        // unusable AND recognisably placeholders, so that a deployment running on
        // them fails loudly instead of quietly doing nothing (§63).
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringNotContainsString('sk_live_', $example);
        $this->assertStringNotContainsString('pk_live_', $example);

        // The shipped test key must be visibly a placeholder, and the live slots
        // must ship empty — so a copied .env cannot accidentally point at a real
        // merchant account.
        $this->assertMatchesRegularExpression(
            '/^PAYSTACK_TEST_SECRET_KEY=sk_test_replace_me$/m',
            $example,
            '.env.example must ship an obviously fake Paystack test secret'
        );
        $this->assertMatchesRegularExpression('/^PAYSTACK_LIVE_SECRET_KEY=\s*$/m', $example);
        $this->assertMatchesRegularExpression('/^PAYSTACK_LIVE_PUBLIC_KEY=\s*$/m', $example);

        // The secret key must be named in the same file that warns about it, so
        // the person copying the file reads the constraint (§41).
        $this->assertStringContainsString('secret key must never reach a view', $example);

        // Every integration ships disabled, so a copied .env never enables a
        // half-configured payment path.
        foreach (['GOOGLE_ENABLED=false', 'MICROSOFT_ENABLED=false', 'ZOOM_ENABLED=false', 'GOOGLE_MEET_ENABLED=false'] as $disabled) {
            $this->assertStringContainsString($disabled, $example);
        }
    }

    public function test_the_secret_key_never_appears_in_a_rendered_response(): void
    {
        $secret = 'sk_test_SYNTHETIC_SECRET_'.bin2hex(random_bytes(8));

        config([
            'platform.integrations.paystack.enabled' => true,
            'services.paystack.secret' => $secret,
            'services.paystack.public' => 'pk_test_SYNTHETIC_PUBLIC_1234567890',
        ]);

        // Every response the platform can produce without authentication. In
        // Phase 8 this list grows to include checkout and receipt pages, which is
        // exactly why the assertion lives here rather than in a payment test.
        $responses = [
            $this->get('/'),
            $this->get('/up'),
            $this->getJson('/api/v1/status'),
            $this->get('/this-page-does-not-exist'),
        ];

        foreach ($responses as $index => $response) {
            $body = $response->getContent();

            $this->assertStringNotContainsString($secret, $body, "Response #{$index} leaked the Paystack secret key");
            $this->assertStringNotContainsString('SYNTHETIC_SECRET', $body, "Response #{$index} leaked the Paystack secret key");
            $this->assertDoesNotMatchRegularExpression(self::KEY_PATTERN, $body, "Response #{$index} contains a key-shaped string");
        }
    }

    public function test_the_secret_is_redacted_from_log_context(): void
    {
        // §77: redaction is structural, not a convention someone remembers. A key
        // matching the configured patterns must be named for scrubbing.
        $patterns = (array) config('platform.logging.redact_value_patterns');

        $this->assertNotEmpty($patterns, 'No log redaction patterns are configured');

        $secret = 'sk_live_'.str_repeat('a', 32);

        $matched = false;

        foreach ($patterns as $pattern) {
            if (preg_match((string) $pattern, $secret) === 1) {
                $matched = true;

                break;
            }
        }

        $this->assertTrue($matched, 'A live Paystack secret key is not matched by any log redaction pattern');

        $keys = (array) config('platform.logging.redact_keys');

        foreach (['secret', 'client_secret', 'secret_key', 'password', 'access_token'] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    /**
     * Recursively collect files under a path, filtered by extension.
     *
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function filesUnder(string $path, array $extensions): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            foreach ($extensions as $extension) {
                if (str_ends_with($file->getFilename(), '.'.$extension)) {
                    $files[] = $file->getPathname();

                    break;
                }
            }
        }

        sort($files);

        return $files;
    }
}
