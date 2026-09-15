<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Money\MoneyFormatter;
use App\Support\Time\TimezonePresenter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Both must be SINGLETONS.
         *
         * TimezonePresenter holds per-request state (the `?tz=` override). If the
         * middleware and a later `user_timezone()` call in a Blade view resolved
         * different instances, the override would apply to the middleware's copy
         * only and the page would render two timezones — one in the header,
         * another in the body. That is a silent, hard-to-reproduce bug, so the
         * binding is made explicit here.
         *
         * MoneyFormatter is stateless; the singleton just avoids re-instantiating
         * it on every formatted amount in a long table.
         */
        $this->app->singleton(TimezonePresenter::class);
        $this->app->singleton(MoneyFormatter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureModelGuards();
        $this->configureRateLimiting();
    }

    /**
     * Turns one class of silent data loss into an exception, outside production.
     *
     * Mass assignment discards any attribute absent from the model's `$fillable`
     * without saying so. That is correct for a request — it is what stops a posted
     * `is_admin=1` — but in application code it is indistinguishable from a typo,
     * and the typo is the common case.
     *
     * This exists because it caught a real defect. DemoUserSeeder passed
     * `email_verified_at` to `updateOrCreate()`; that column is deliberately not
     * fillable, because a request able to set it would let anyone mark their own
     * account verified. So six demo accounts were created unverified while looking
     * perfectly seeded — and every one of them would have been refused at the
     * email-verification gate. An exception in development costs nothing; a login
     * that quietly refuses people who were told their account works does not.
     *
     * Production is excluded so a development guard can never become the reason a
     * payment webhook returns 500. The gate is CI and local runs, where the
     * exception surfaces as a failing test rather than an incident.
     */
    private function configureModelGuards(): void
    {
        if ($this->app->environment('production')) {
            return;
        }

        Model::preventSilentlyDiscardingAttributes();
    }

    /**
     * Rate limits that exist to protect real people and real third-party quotas.
     *
     * The auth limits matter most: credential stuffing against a parent portal
     * is not theoretical, and a shared Google/Zoom quota can be exhausted by one
     * runaway script, taking meetings down for every tutor on the platform (§75).
     */
    private function configureRateLimiting(): void
    {
        // Public API: generous for legitimate clients, cheap to abuse-detect.
        [$apiAttempts, $apiDecay] = $this->parseLimit((string) config('platform.api.rate_limit', '60:1'));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinutes($apiDecay, $apiAttempts)->by(
            (string) ($request->user()?->getAuthIdentifier() ?: $request->ip())
        ));

        // Webhooks come from a handful of provider IPs and are HMAC-verified;
        // the limit exists to blunt a flood of forged deliveries, not to throttle
        // a real provider. Deliberately high.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        // Authentication endpoints: slow enough that a stolen password list is
        // impractical, fast enough that a parent retrying a typo is not locked
        // out of their child's results.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by(
            strtolower((string) $request->input('email', $request->ip()))
        ));

        // Password resets and verification resends cost real money (email) and
        // are a harassment vector, so they are limited hardest.
        RateLimiter::for('auth-sensitive', fn (Request $request) => Limit::perMinute(3)->by(
            strtolower((string) $request->input('email', $request->ip()))
        ));
    }

    /**
     * Parse Laravel's "maxAttempts:decayMinutes" rate-limit string.
     *
     * @return array{0: int, 1: int}
     */
    private function parseLimit(string $limit): array
    {
        $parts = explode(':', $limit, 2);

        $attempts = (int) ($parts[0] ?? 60);
        $decay = (int) ($parts[1] ?? 1);

        return [max(1, $attempts), max(1, $decay)];
    }
}
