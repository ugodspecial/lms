<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Money\MoneyFormatter;
use App\Support\Time\TimezonePresenter;
use Illuminate\Cache\RateLimiting\Limit;
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
        $this->configureRateLimiting();
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
