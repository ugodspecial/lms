<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Time;

use App\Domain\Identity\Models\User;
use App\Support\Time\TimezonePresenter;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Throwable;

/**
 * Timezone resolution and UTC conversion (§62, ADR-08).
 *
 * The invariant under test: **storage is always UTC, display is always the
 * viewer's zone.** A lesson booked for 16:00 in Lagos must be stored as 15:00
 * UTC and shown as 16:00 to the Lagos parent and 15:00 to a guardian visiting
 * from London. Getting this wrong is not a display nit — it is a student missing
 * a paid lesson.
 */
final class TimezonePresenterTest extends TestCase
{
    private function presenter(): TimezonePresenter
    {
        return new TimezonePresenter(app(AuthFactory::class));
    }

    public function test_it_falls_back_to_the_platform_default_for_an_anonymous_visitor(): void
    {
        config(['platform.timezone.default' => 'Africa/Lagos']);

        $this->assertSame('Africa/Lagos', $this->presenter()->current());
    }

    public function test_it_falls_back_to_utc_when_the_configured_default_is_invalid(): void
    {
        // A typo in .env must not take the whole platform down with an
        // "Unknown or bad timezone" fatal on every request.
        config(['platform.timezone.default' => 'Mars/Olympus_Mons']);

        $this->assertSame('UTC', $this->presenter()->current());
    }

    public function test_it_prefers_the_authenticated_users_own_timezone(): void
    {
        config(['platform.timezone.default' => 'Africa/Lagos']);

        $user = new User(['name' => 'Parent', 'email' => 'p@example.test']);
        $user->timezone = 'Europe/London';

        $this->actingAs($user);

        $this->assertSame('Europe/London', $this->presenter()->current());
    }

    public function test_an_explicit_override_wins_over_the_user_profile(): void
    {
        config(['platform.timezone.default' => 'Africa/Lagos']);

        $user = new User(['name' => 'Tutor', 'email' => 't@example.test']);
        $user->timezone = 'Africa/Lagos';
        $this->actingAs($user);

        $presenter = $this->presenter();
        $presenter->override('Asia/Kolkata');

        // A tutor teaching a student abroad needs to see the schedule in the
        // student's zone without changing their own profile.
        $this->assertSame('Asia/Kolkata', $presenter->current());
    }

    public function test_an_invalid_override_is_ignored_rather_than_applied(): void
    {
        config(['platform.timezone.default' => 'Africa/Lagos']);

        $presenter = $this->presenter();
        $presenter->override('Not/AZone');

        $this->assertSame('Africa/Lagos', $presenter->current());

        // A `?tz=` query parameter is attacker-controllable input reaching a
        // timezone function; an unchecked value here is a crash on every page.
        $presenter->override('../../etc/passwd');
        $this->assertSame('Africa/Lagos', $presenter->current());

        $presenter->override(null);
        $this->assertSame('Africa/Lagos', $presenter->current());
    }

    public function test_it_renders_a_stored_utc_instant_in_the_viewers_zone(): void
    {
        $stored = Carbon::parse('2026-03-14 15:00:00', 'UTC');

        config(['platform.timezone.default' => 'Africa/Lagos']);
        $this->assertSame(
            'Sat, 14 Mar 2026 4:00 PM',
            $this->presenter()->render($stored, 'D, j M Y g:i A')
        );

        config(['platform.timezone.default' => 'Europe/London']);
        $this->assertSame(
            'Sat, 14 Mar 2026 3:00 PM',
            $this->presenter()->render($stored, 'D, j M Y g:i A')
        );
    }

    public function test_rendering_does_not_mutate_the_instant_it_was_given(): void
    {
        // A shared Carbon instance mutated by rendering would silently shift the
        // stored time for everything rendered afterwards in the same request.
        $stored = Carbon::parse('2026-03-14 15:00:00', 'UTC');

        config(['platform.timezone.default' => 'Asia/Tokyo']);
        $this->presenter()->render($stored);

        $this->assertSame('UTC', $stored->getTimezone()->getName());
        $this->assertSame('2026-03-14 15:00:00', $stored->format('Y-m-d H:i:s'));
    }

    public function test_it_renders_a_missing_instant_as_an_em_dash_not_a_zero_date(): void
    {
        // "1 Jan 1970" on a timetable reads as a real, wrong date. An em dash is
        // unambiguous.
        $this->assertSame('—', $this->presenter()->render(null));
    }

    public function test_it_converts_an_entered_wall_clock_time_to_utc_for_storage(): void
    {
        $presenter = $this->presenter();

        // 16:00 in Lagos (UTC+1, no daylight saving) is 15:00 UTC.
        $utc = $presenter->wallClockToUtc('16:00', 'Africa/Lagos', '2026-03-14');
        $this->assertSame('2026-03-14 15:00:00', $utc->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $utc->getTimezone()->getName());

        // London in March 2026 is still GMT (clocks change on 29 March), so
        // 16:00 is also 16:00 UTC — the same wall clock, a different instant.
        $london = $presenter->wallClockToUtc('16:00', 'Europe/London', '2026-03-14');
        $this->assertSame('2026-03-14 16:00:00', $london->format('Y-m-d H:i:s'));

        // ...and after the change it is BST, so the stored UTC differs. This is
        // precisely why the timezone must be stored alongside the wall clock.
        $londonSummer = $presenter->wallClockToUtc('16:00', 'Europe/London', '2026-06-14');
        $this->assertSame('2026-06-14 15:00:00', $londonSummer->format('Y-m-d H:i:s'));
    }

    public function test_an_invalid_timezone_on_conversion_falls_back_to_utc(): void
    {
        $utc = $this->presenter()->wallClockToUtc('16:00', 'Not/AZone', '2026-03-14');

        $this->assertSame('2026-03-14 16:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_the_timezone_picker_options_are_all_valid_iana_identifiers(): void
    {
        $choices = TimezonePresenter::choices();

        $this->assertNotEmpty($choices);
        $this->assertContains('Africa/Lagos', $choices);
        $this->assertNotContains('UTC+1', $choices);

        // Every identifier offered in a select box must be one Carbon actually
        // accepts, or saving a profile throws on a value the platform itself
        // suggested.
        foreach ($choices as $identifier) {
            try {
                Carbon::now($identifier);
            } catch (Throwable $e) {
                $this->fail("Timezone picker offered an unusable identifier [{$identifier}]: {$e->getMessage()}");
            }
        }

        $this->addToAssertionCount(count($choices));
    }

    public function test_the_global_helper_agrees_with_the_presenter(): void
    {
        config(['platform.timezone.default' => 'Africa/Lagos']);

        $this->assertSame($this->presenter()->current(), user_timezone());

        $stored = Carbon::parse('2026-03-14 15:00:00', 'UTC');
        $this->assertSame(
            $this->presenter()->render($stored, 'D, j M Y g:i A'),
            in_timezone($stored, 'D, j M Y g:i A')
        );
    }

    public function test_in_timezone_handles_a_string_instant_and_a_null(): void
    {
        config(['platform.timezone.default' => 'Africa/Lagos']);

        $this->assertSame('—', in_timezone(null));
        $this->assertSame(
            'Sat, 14 Mar 2026 4:00 PM',
            in_timezone('2026-03-14 15:00:00', 'D, j M Y g:i A')
        );
        $this->assertSame(
            'Sat, 14 Mar 2026 3:00 PM',
            in_timezone('2026-03-14 15:00:00', 'D, j M Y g:i A', 'Europe/London')
        );
    }
}
