<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Global helpers
|--------------------------------------------------------------------------
|
| Autoloaded via composer.json `autoload.files`. Keep this file SMALL: only
| genuinely cross-cutting, framework-shaped helpers belong here. Business logic
| lives in domain services and actions (ADR-14) — never in a helper, because a
| helper cannot be dependency-injected, mocked or unit-tested in isolation.
|
| Phase 0 shipped only the helpers whose dependencies are pure (no database).
| Phase 1 adds `setting()`, which reads the `settings` table through
| SettingsService — the point of §82's "nothing business-critical is
| hard-coded", reachable from a Blade file without injecting a service.
|
*/

use App\Domain\Administration\Services\SettingsService;
use App\Support\Money\MoneyFormatter;
use App\Support\Time\TimezonePresenter;
use Illuminate\Support\Carbon;

if (! function_exists('money')) {
    /**
     * Format integer minor units for display using the organisation's currency.
     *
     * The symbol comes from config('platform.currencies') — never hard-coded,
     * so no ₦ leaks into business logic (§61, ADR-02).
     */
    function money(int $minorUnits, ?string $currency = null): string
    {
        return app(MoneyFormatter::class)->format($minorUnits, $currency);
    }
}

if (! function_exists('user_timezone')) {
    /**
     * The timezone the current request should be displayed in (§62, ADR-08).
     */
    function user_timezone(): string
    {
        return app(TimezonePresenter::class)->current();
    }
}

if (! function_exists('in_timezone')) {
    /**
     * Render a stored UTC instant in a viewer's timezone.
     *
     * Every instant is stored in UTC; conversion happens only at the edge
     * (ADR-08). Never convert before storing.
     */
    function in_timezone(mixed $utcInstant, string $format = 'D, j M Y g:i A', ?string $timezone = null): string
    {
        if ($utcInstant === null) {
            return '—';
        }

        $carbon = $utcInstant instanceof Carbon
            ? $utcInstant->copy()
            : Carbon::parse($utcInstant, 'UTC');

        return $carbon->setTimezone($timezone ?? user_timezone())->translatedFormat($format);
    }
}

if (! function_exists('feature_enabled')) {
    /**
     * Structural feature flag (§93).
     *
     * When false the entry point is not rendered at all — the platform never
     * shows a button that cannot work.
     */
    function feature_enabled(string $feature): bool
    {
        return (bool) config("platform.features.{$feature}", false);
    }
}

if (! function_exists('setting')) {
    /**
     * Read a business value from the `settings` table (§60, §82, docs/01 §5.3).
     *
     * The default is a parameter and not a config lookup on purpose. Falling back
     * to `config()` by key name would make `setting('app.key')` and
     * `setting('services.paystack.secret')` return the very secrets §30.7 keeps in
     * `.env`, so the caller decides what a missing value means — and where a
     * config equivalent exists, that is what should be passed:
     *
     *     setting('platform.currency', config('platform.currency'))
     *
     * One place holds the fallback then, and the setting overrides it. Secrets
     * (`is_secret`) are filtered out by SettingsService and always yield the
     * default, so this helper cannot read one no matter what key it is given.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($key, $default);
    }
}
