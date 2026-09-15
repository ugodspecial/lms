<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Administration\Actions\UpdateSetting;
use App\Domain\Administration\Enums\SettingGroup;
use App\Domain\Administration\Enums\SettingType;
use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Models\Setting;
use App\Domain\Administration\Services\SettingsService;
use App\Exceptions\PlatformException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * The use case behind "an administrator changed a setting" (§60, docs/01 §5.3,
 * docs/04 §9: every change to `settings` is audited old→new).
 *
 * The action is deliberately thin, and these tests hold it there. It resolves a
 * key, it shapes the failure a human sees when the key is not registered, and it
 * delegates. Two things it must NOT do, because each one would silently break a
 * guarantee made elsewhere:
 *
 * • It must not create. The registry of settings — which keys exist, what type
 *   they hold, whether they are secret, what a choice may be — is code, reviewed
 *   and shipped. An action that created rows on demand would let a form widen
 *   `allowed_values` or clear `is_secret` one request later (see Setting's
 *   fillable list).
 *
 * • It must not re-check authorization. SettingPolicy answers that, asked by the
 *   Form Request or controller. An action that also checked could not be reused
 *   from a console command, an installer or a seeder, where the right to act has
 *   already been established and there is no principal to check against.
 */
final class UpdateSettingTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    private function action(): UpdateSetting
    {
        return app(UpdateSetting::class);
    }

    public function test_it_resolves_a_key_and_writes_the_value(): void
    {
        $setting = $this->makeSetting('tests.currency', 'USD', SettingType::Text, [
            'group' => SettingGroup::Organization,
        ]);

        $returned = $this->action()->execute('tests.currency', 'NGN');

        $this->assertSame($setting->getKey(), $returned->getKey());
        $this->assertSame('NGN', $returned->typedValue());
        $this->assertSame('NGN', $setting->fresh()->typedValue());
    }

    public function test_an_unregistered_key_is_a_404_and_is_not_created_on_the_way_past(): void
    {
        try {
            $this->action()->execute('tests.never_registered', 'anything');

            $this->fail('An unknown key must not become a new setting; the registry is code, not a form.');
        } catch (PlatformException $exception) {
            $this->assertSame('settings.unknown_key', $exception->getErrorCode());
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertStringContainsString('tests.never_registered', $exception->getErrors()['key'][0]);
        }

        $this->assertDatabaseMissing('settings', ['key' => 'tests.never_registered']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'settings.updated']);
    }

    public function test_it_refuses_a_value_the_setting_cannot_hold(): void
    {
        $setting = $this->makeSetting('tests.cancellation_window_hours', 24, SettingType::Integer);

        try {
            // Exactly what an HTML form submits. The HTTP layer owns the cast;
            // refusing here is what makes that requirement impossible to miss.
            $this->action()->execute('tests.cancellation_window_hours', '48');

            $this->fail('A numeric string must be cast by the caller, not coerced on the way into a JSON column.');
        } catch (PlatformException $exception) {
            $this->assertSame('settings.type_mismatch', $exception->getErrorCode());
        }

        $this->assertSame(24, $setting->fresh()->typedValue());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'settings.updated']);
    }

    public function test_it_records_who_changed_what_from_what_to_what(): void
    {
        $administrator = $this->makeUser();
        $this->makeSetting('tests.currency', 'USD');

        $this->action()->execute('tests.currency', 'NGN', $administrator);

        $log = AuditLog::query()->ofEvent('settings.updated')->firstOrFail();

        $this->assertSame($administrator->id, $log->user_id);
        $this->assertEquals(['tests.currency' => 'USD'], $log->old_values);
        $this->assertEquals(['tests.currency' => 'NGN'], $log->new_values);
    }

    public function test_the_actor_defaults_to_the_authenticated_administrator(): void
    {
        $administrator = $this->makeUser();
        $this->actingAs($administrator);
        $this->makeSetting('tests.currency', 'USD');

        $this->action()->execute('tests.currency', 'NGN');

        $log = AuditLog::query()->ofEvent('settings.updated')->firstOrFail();

        $this->assertSame($administrator->id, $log->user_id);
    }

    public function test_a_write_through_the_action_is_visible_to_the_readers_of_the_helper(): void
    {
        $this->makeSetting('tests.currency', 'USD');

        // Warm the cache the way a page render would, then change the value
        // through the action a controller calls. This is the end-to-end claim: an
        // administrator saves a form and the next page — which reads through
        // `setting()`, one cache entry deep — shows the new value.
        $this->assertSame('USD', setting('tests.currency'));
        $this->assertTrue(Cache::has(SettingsService::CACHE_KEY));

        $this->action()->execute('tests.currency', 'NGN');

        $this->assertSame('NGN', setting('tests.currency'));
    }

    public function test_the_action_does_not_repeat_the_authorization_the_edge_already_performed(): void
    {
        $student = $this->makeUser();
        $this->makeSetting('tests.currency', 'USD');

        $this->action()->execute('tests.currency', 'NGN', $student);

        $setting = Setting::query()->where('key', 'tests.currency')->firstOrFail();

        // The layer that matters at the edge still says no, and says so on its own:
        // nothing about the action having run changed the answer.
        $this->assertFalse(
            $student->can('update', $setting),
            'SettingPolicy must refuse a student independently of anything the action does.',
        );

        $this->assertSame('NGN', setting('tests.currency'));

        // And if an action is ever reached from somewhere it should not have been,
        // the trail names the principal it was given.
        $this->assertSame($student->id, AuditLog::query()->ofEvent('settings.updated')->firstOrFail()->user_id);
    }
}
