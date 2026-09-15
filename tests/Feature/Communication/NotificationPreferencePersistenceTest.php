<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Domain\Communication\Models\NotificationPreference;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * Per-user notification choices (§82), and the one column that decides whether a
 * choice may be made at all.
 *
 * The table exists so the settings screen in Phase 1 has somewhere to put its
 * toggles — a control that saves nothing is a dead control, which §63 forbids.
 * What is worth testing is the part that is easy to get wrong in the permissive
 * direction: `is_transactional` marks the notifications a user may NOT turn off,
 * and if a request could set that flag, the rule would only ever apply to people
 * who did not bother to disable it.
 */
final class NotificationPreferencePersistenceTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    public function test_a_new_preference_defaults_to_every_channel_being_on(): void
    {
        $preference = $this->makePreference($this->makeUser(), 'booking.confirmed')->fresh();

        // The defaults are the platform's position: everything is delivered unless
        // somebody chose otherwise. A user with no rows at all has every
        // notification enabled, so a missing row must never be read as an opt-out.
        $this->assertTrue($preference->channel_mail);
        $this->assertTrue($preference->channel_database);
        $this->assertFalse($preference->is_transactional);
        $this->assertTrue($preference->allowsOptOut());
    }

    public function test_the_same_key_cannot_be_stored_twice_for_one_user(): void
    {
        $user = $this->makeUser();

        $this->makePreference($user, 'booking.confirmed');

        // Two rows for one key means two answers to "did they opt out?", and
        // whichever one the query returns first decides whether a parent is told
        // their child's session was cancelled.
        $this->expectException(QueryException::class);

        $this->makePreference($user, 'booking.confirmed');
    }

    public function test_the_same_key_is_stored_once_per_user(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();

        $this->makePreference($first, 'booking.confirmed', ['channel_mail' => false]);
        $this->makePreference($second, 'booking.confirmed');

        $this->assertFalse($first->notificationPreferences()->forKey('booking.confirmed')->firstOrFail()->channel_mail);
        $this->assertTrue($second->notificationPreferences()->forKey('booking.confirmed')->firstOrFail()->channel_mail);
    }

    public function test_a_choice_is_recorded_against_the_person_who_made_it(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->makePreference($user, 'newsletter.digest', ['channel_mail' => false]);
        $this->makePreference($other, 'newsletter.digest');

        $this->assertSame(1, $user->notificationPreferences()->count());
        $this->assertTrue($user->notificationPreferences()->firstOrFail()->user->is($user));
    }

    public function test_whether_a_notification_may_be_turned_off_cannot_be_posted(): void
    {
        // Denormalised onto the row so the UI can render the toggle disabled and
        // the service can refuse without a second lookup — but it is a property of
        // the notification, set when the row is created. A request able to carry it
        // could mark a payment receipt or a safeguarding alert as optional.
        $this->expectException(MassAssignmentException::class);

        (new NotificationPreference)->fill([
            'notification_key' => 'payment.received',
            'channel_mail' => false,
            'is_transactional' => false,
        ]);
    }

    public function test_a_transactional_preference_reports_that_it_cannot_be_turned_off(): void
    {
        $preference = $this->makePreference($this->makeUser(), 'payment.received', [
            'is_transactional' => true,
            'channel_mail' => false,
        ])->fresh();

        $this->assertTrue($preference->is_transactional);
        $this->assertFalse($preference->allowsOptOut());

        // The stored channel value is beside the point. A request can post
        // `channel_mail = false` for a transactional key all it likes; the service
        // has to ignore it, because §82's rule cannot depend on a browser honouring
        // a disabled attribute. What the model owes the service is an honest answer
        // to the one question above.
        $this->assertFalse($preference->channel_mail);
    }

    public function test_preferences_are_removed_when_the_person_is_erased(): void
    {
        $user = $this->makeUser();

        $this->makePreference($user, 'booking.confirmed');
        $this->makePreference($user, 'newsletter.digest');

        // CASCADE here, where every other reference to `users` restricts: a
        // preference has no academic or evidential value once the person is gone,
        // and keeping it would retain data about an erased user for no reason
        // (§58, docs/04 §66.10). A hard delete is used because that is what erasure
        // is; ordinary deactivation is a soft delete and keeps everything.
        $user->forceDelete();

        $this->assertSame(0, NotificationPreference::where('user_id', $user->id)->count());
        $this->assertFalse(User::withTrashed()->whereKey($user->id)->exists());
    }
}
