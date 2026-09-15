<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Administration\Enums\SettingGroup;
use App\Domain\Administration\Enums\SettingType;
use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Services\SettingsService;
use App\Exceptions\PlatformException;
use App\Support\Logging\SensitiveDataScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * Settings are how the platform keeps business values out of code (§60, §61, §82,
 * docs/01 §5.3): academic years, grade scales, the age of majority, cancellation
 * windows, tax rates, currency, the video provider.
 *
 * Three claims are being tested, and each one has a silent failure mode:
 *
 * • TYPE. Everything is stored in one JSON column, so a setting declared `int` can
 *   come back as the string "5" depending on who last wrote it. Callers compare
 *   with `===`, and a caller that got a string would take the wrong branch on a
 *   value that looks right in the database.
 *
 * • CACHE. One entry for the whole set, because a page reads a dozen settings and
 *   the cache on shared hosting is the filesystem or the database (ADR-06) — a
 *   dozen lookups is a dozen round trips. The cost is that a write which forgets
 *   to bust it serves the old value for the whole TTL, which looks like the
 *   setting being ignored rather than the cache being warm.
 *
 * • SECRETS NEVER LEAVE. `all()` and `get()` filter `is_secret` rows out before
 *   anything else happens, so there is no path from this service to a secret
 *   value — not one to guard, because none exists (docs/06's
 *   SecretNeverExposedTest). Code that genuinely needs one reads the Setting model
 *   directly, which is a reviewable choice rather than an accident of calling
 *   `setting()`.
 */
final class SettingsServiceTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    private function service(): SettingsService
    {
        return app(SettingsService::class);
    }

    // ── Typed reads ─────────────────────────────────────────────────────────

    public function test_each_declared_type_comes_back_as_the_type_it_declares(): void
    {
        // Every value below is stored in the shape a careless writer would leave
        // it in — a number as a string, a boolean as 1 — which is the actual state
        // of a JSON column after a while. typedValue() is what makes a reader's
        // `===` mean the same thing every day.
        $this->makeSetting('tests.organization_name', 'Lagos Academy', SettingType::Text);
        $this->makeSetting('tests.max_students', '30', SettingType::Integer);
        $this->makeSetting('tests.enrolment_open', 1, SettingType::Boolean);
        $this->makeSetting('tests.platform_fee_percent', '12.5', SettingType::Decimal);
        $this->makeSetting('tests.grading_bands', ['A' => 70, 'B' => 55], SettingType::Json);
        $this->makeSetting('tests.payment_provider', 'paystack', SettingType::Choice);

        $all = $this->service()->all();

        $this->assertSame('Lagos Academy', $all['tests.organization_name']);
        $this->assertSame(30, $all['tests.max_students']);
        $this->assertTrue($all['tests.enrolment_open']);
        $this->assertSame(12.5, $all['tests.platform_fee_percent']);
        $this->assertEquals(['A' => 70, 'B' => 55], $all['tests.grading_bands']);
        $this->assertSame('paystack', $all['tests.payment_provider']);

        $this->assertTrue($this->service()->isReadable());
    }

    public function test_a_key_nobody_has_set_returns_the_callers_default(): void
    {
        $service = $this->service();

        // The documented call pattern: the default comes from config, so the value
        // lives in exactly one place until somebody overrides it in the database.
        $this->assertSame(
            config('platform.currency'),
            $service->get('platform.currency', config('platform.currency')),
        );
        $this->assertNull($service->get('platform.currency'));
        $this->assertSame(18, $service->get('platform.age_of_majority', 18));
    }

    public function test_a_setting_whose_stored_value_is_null_falls_back_too(): void
    {
        // NULL is how an operator clears an override, and it has to behave like
        // "not set" rather than like a value.
        $this->makeSetting('tests.optional_note', null, SettingType::Text);

        $this->assertSame('none', $this->service()->get('tests.optional_note', 'none'));
    }

    // ── Secrets ─────────────────────────────────────────────────────────────

    public function test_a_secret_is_never_returned_by_any_read_path(): void
    {
        $this->makeSetting('payments.merchant_id', 'MCH-9931-live', SettingType::Text, [
            'group' => SettingGroup::Payments,
            'is_secret' => true,
        ]);
        $this->makeSetting('payments.mode', 'test', SettingType::Text, [
            'group' => SettingGroup::Payments,
        ]);

        $service = $this->service();
        $all = $service->all();

        // The non-secret in the SAME group comes back, so this is about is_secret
        // and not about the group, the key name or the type.
        $this->assertSame('test', $all['payments.mode']);

        $this->assertArrayNotHasKey('payments.merchant_id', $all);
        $this->assertSame('fallback', $service->get('payments.merchant_id', 'fallback'));
        $this->assertNull($service->get('payments.merchant_id'));
        $this->assertSame('fallback', setting('payments.merchant_id', 'fallback'));
        $this->assertStringNotContainsString('MCH-9931-live', json_encode($all, JSON_THROW_ON_ERROR));
    }

    public function test_the_helper_never_falls_back_to_config_by_key_name(): void
    {
        $this->makeSetting('tests.currency', 'GHS');

        $this->assertSame('GHS', setting('tests.currency'));

        // The tempting implementation — "if there is no row, try config($key)" —
        // would make these two calls return APP_KEY and the Paystack secret, which
        // §30.7 keeps in .env precisely so that no key string can reach them. The
        // default is a parameter, so the caller decides what a missing value means.
        $this->assertSame('fallback', setting('app.key', 'fallback'));
        $this->assertSame('fallback', setting('services.paystack.secret', 'fallback'));
        $this->assertNull(setting('database.password'));
    }

    // ── Cache ───────────────────────────────────────────────────────────────

    public function test_a_second_read_issues_no_queries_at_all(): void
    {
        $this->makeSetting('tests.one', 'first');

        $service = $this->service();
        $this->assertSame('first', $service->get('tests.one'));
        $this->assertTrue(Cache::has(SettingsService::CACHE_KEY));

        DB::enableQueryLog();
        $second = $service->all();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'The whole set is one cache entry, so a warm read touches nothing.');
        $this->assertSame('first', $second['tests.one']);
    }

    public function test_a_write_busts_the_cache_so_the_next_read_sees_it(): void
    {
        $setting = $this->makeSetting('tests.currency', 'USD');
        $service = $this->service();

        $this->assertSame('USD', $service->get('tests.currency'));
        $this->assertTrue(Cache::has(SettingsService::CACHE_KEY));

        $service->set($setting, 'NGN');

        $this->assertFalse(
            Cache::has(SettingsService::CACHE_KEY),
            'A write that leaves the cache warm serves the previous value for the whole TTL.',
        );
        $this->assertSame('NGN', $service->get('tests.currency'));
        $this->assertSame('NGN', $setting->fresh()->typedValue());
    }

    public function test_writing_the_value_a_setting_already_holds_does_nothing(): void
    {
        $setting = $this->makeSetting('tests.currency', 'NGN');
        $service = $this->service();
        $service->all();

        $returned = $service->set($setting, 'NGN');

        // "Changed X to what X already was" would bury the entries that matter in a
        // trail an administrator has to read, so there is no UPDATE and no entry.
        $this->assertSame($setting->getKey(), $returned->getKey());
        $this->assertTrue(Cache::has(SettingsService::CACHE_KEY), 'Nothing changed, so the cache must still be warm.');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'settings.updated']);
    }

    // ── Writes are validated ────────────────────────────────────────────────

    public function test_a_value_of_the_wrong_type_is_refused_rather_than_coerced(): void
    {
        $setting = $this->makeSetting('tests.max_students', 30, SettingType::Integer);

        try {
            // This is exactly what an HTML form submits. Coercing it here would
            // store a string in a column whose declared type says int, and the next
            // reader would get a different answer than the last one.
            $this->service()->set($setting, '30');

            $this->fail('A numeric string has to be cast by the caller, not silently coerced by the store.');
        } catch (PlatformException $exception) {
            $this->assertSame('settings.type_mismatch', $exception->getErrorCode());
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertArrayHasKey('value', $exception->getErrors());
        }

        $this->assertSame(30, $setting->fresh()->typedValue());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'settings.updated']);
    }

    public function test_a_choice_setting_refuses_a_value_outside_its_allow_list(): void
    {
        $setting = $this->makeSetting('tests.payment_provider', 'paystack', SettingType::Choice, [
            'allowed_values' => ['paystack', 'flutterwave'],
        ]);

        $this->assertSame('flutterwave', $this->service()->set($setting, 'flutterwave')->typedValue());

        try {
            $this->service()->set($setting, 'stripe');

            $this->fail('The allow-list is what turns a free-text box into a select that cannot be filled with anything.');
        } catch (PlatformException $exception) {
            $this->assertSame('settings.value_not_allowed', $exception->getErrorCode());
            $this->assertSame(['paystack', 'flutterwave'], $exception->getContext()['allowed']);
        }

        $this->assertSame('flutterwave', $setting->fresh()->typedValue());
    }

    // ── Every write is audited ──────────────────────────────────────────────

    public function test_a_write_records_who_changed_what_from_what_to_what(): void
    {
        $actor = $this->makeUser();
        $setting = $this->makeSetting('tests.currency', 'USD');

        $this->service()->set($setting, 'NGN', $actor);

        $log = AuditLog::query()->ofEvent('settings.updated')->firstOrFail();

        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame($setting->getKey(), $log->auditable_id);
        $this->assertEquals(['tests.currency' => 'USD'], $log->old_values);
        $this->assertEquals(['tests.currency' => 'NGN'], $log->new_values);
        $this->assertSame(['settings', 'organization'], $log->tagList(), 'The group is a tag, so the trail can be filtered by tab.');
    }

    public function test_a_secret_write_is_audited_without_its_value(): void
    {
        // `payments.merchant_id` is chosen because the name matches nothing in the
        // redaction list: if the explicit is_secret check were removed, the value
        // would reach the table and only this test would notice. The trail records
        // that a secret changed, never what it changed to.
        $setting = $this->makeSetting('payments.merchant_id', 'MCH-old', SettingType::Text, [
            'group' => SettingGroup::Payments,
            'is_secret' => true,
        ]);

        $this->service()->set($setting, 'MCH-9931-live');

        $old = (string) DB::table('audit_logs')->where('event', 'settings.updated')->value('old_values');
        $new = (string) DB::table('audit_logs')->where('event', 'settings.updated')->value('new_values');

        $this->assertStringNotContainsString('MCH-9931-live', $new);
        $this->assertStringNotContainsString('MCH-old', $old);

        $log = AuditLog::query()->ofEvent('settings.updated')->firstOrFail();
        $this->assertSame(SensitiveDataScrubber::REDACTED, $log->new_values['payments.merchant_id']);

        // Withholding the value from the trail is not withholding the change.
        $this->assertSame('MCH-9931-live', $setting->fresh()->typedValue());
    }
}
