<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Time\TimezonePresenter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Put the viewer's timezone in scope for the whole request (§62, ADR-08).
 *
 * Instants are stored in UTC and converted only at the edge, so every read path
 * needs to know *whose* edge it is. Resolving once per request keeps Blade,
 * Livewire and API resources in agreement and removes the classic bug where a
 * timetable shows one time to a parent in Lagos and a different one in the PDF
 * generated moments later.
 *
 * An explicit `?tz=` override is honoured only when it is a valid IANA
 * identifier — an attacker-supplied timezone must never become a crash or an
 * injection vector.
 */
final class SetUserTimezone
{
    public function __construct(private readonly TimezonePresenter $timezones) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($override = $request->query('tz')) {
            $this->timezones->override((string) $override);
        }

        $timezone = $this->timezones->current();

        // Laravel's own date handling, so Carbon::now() inside a request is
        // already the viewer's clock.
        config(['app.display_timezone' => $timezone]);
        date_default_timezone_set($timezone);

        // Share with every view; Blade can use $timezone directly.
        view()->share('timezone', $timezone);

        // Livewire components and API resources read this attribute.
        $request->attributes->set('display_timezone', $timezone);

        return $next($request);
    }
}
