<?php

declare(strict_types=1);

namespace App\Support\Time;

use DateTimeZone;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Carbon;

/**
 * Resolves the timezone a response should be *displayed* in (§62, ADR-08).
 *
 * Precedence, highest first:
 *   1. An explicit override for the current request (admin impersonating a
 *      region, or a user switching to see a schedule in another timezone).
 *   2. The authenticated user's own profile timezone.
 *   3. The organisation default from config.
 *   4. UTC as the final, always-valid fallback.
 *
 * Storage is the mirror image: every instant is written in UTC regardless of
 * what this class resolves. This class only ever affects rendering.
 */
final class TimezonePresenter
{
    /** A per-request override, e.g. ?tz=Europe/London on a printable timetable. */
    private ?string $override = null;

    public function __construct(private readonly AuthFactory $auth) {}

    /** Restrict rendering to a specific timezone for this request only. */
    public function override(?string $timezone): void
    {
        $this->override = $this->isValid($timezone) ? $timezone : null;
    }

    public function current(): string
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $user = $this->auth->guard(null)->user();

        // `getAttribute()` rather than property access: Eloquent stores columns in
        // an internal attributes array, so `property_exists()` is always false for
        // a model column. It also returns null safely for a model that does not
        // have the column yet, which keeps this working before the Phase 1
        // migration adds `users.timezone`.
        $userTimezone = '';

        if ($user !== null && method_exists($user, 'getAttribute')) {
            $userTimezone = (string) ($user->getAttribute('timezone') ?? '');
        }

        if ($this->isValid($userTimezone)) {
            return $userTimezone;
        }

        $default = (string) config('platform.timezone.default', 'UTC');

        return $this->isValid($default) ? $default : 'UTC';
    }

    /** Render an instant (stored in UTC) in the current viewer's timezone. */
    public function render(mixed $utcInstant, string $format = 'D, j M Y g:i A'): string
    {
        if ($utcInstant === null) {
            return '—';
        }

        $carbon = $utcInstant instanceof Carbon
            ? $utcInstant->copy()
            : Carbon::parse($utcInstant, 'UTC');

        return $carbon->setTimezone($this->current())->translatedFormat($format);
    }

    /**
     * Convert a wall-clock time entered in a given timezone to UTC for storage.
     *
     * Used when creating a lesson at "16:00 Africa/Lagos" — the stored instant
     * must be UTC so a parent in London sees the correct local time (§62).
     */
    public function wallClockToUtc(string $wallClock, string $timezone, ?string $date = null): Carbon
    {
        $tz = $this->isValid($timezone) ? $timezone : 'UTC';
        $date ??= Carbon::now($tz)->toDateString();

        return Carbon::parse("{$date} {$wallClock}", $tz)->utc();
    }

    /**
     * Every IANA identifier PHP knows about, for the profile timezone picker.
     *
     * @return list<string>
     */
    public static function choices(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    private function isValid(?string $timezone): bool
    {
        return is_string($timezone) && $timezone !== '' && in_array($timezone, self::choices(), true);
    }
}
