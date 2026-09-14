<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Administration\Enums\SettingGroup;
use App\Domain\Administration\Enums\SettingType;
use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Models\Setting;
use App\Domain\Administration\Services\AuditLogger;
use App\Support\Logging\SensitiveDataScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * The audit trail is the platform's answer to "who changed this, and what was it
 * before?" (§56, ADR-12). These tests cover the four ways it can be worthless
 * without looking broken:
 *
 * • an entry that names no actor, or the wrong one;
 * • an entry that stores the secret it was describing (a plaintext password in a
 *   table administrators can browse makes `audit_logs` the most valuable table in
 *   the database, §77);
 * • an entry that redacted so much it records nothing — `grade_code: [REDACTED]`
 *   tells an investigator only that somebody did something;
 * • an entry that survived the change it describes, or the change that survived
 *   without it. Both are indistinguishable from tampering, which is why the
 *   transaction test below is the one that matters most.
 *
 * Event and tag shape is enforced here rather than trimmed, because those are
 * literals in code: a name too long for the column, or a tag containing a comma,
 * is a defect that should fail the operation it belongs to instead of writing a
 * record nobody can search for.
 */
final class AuditLoggerTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    private function logger(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    // ── The entry itself ────────────────────────────────────────────────────

    public function test_an_entry_names_the_actor_the_subject_and_the_moment(): void
    {
        $actor = $this->makeUser();
        $setting = $this->makeSetting(
            'tests.currency',
            'NGN',
            SettingType::Text,
            ['group' => SettingGroup::Organization],
        );

        $log = $this->logger()->record(
            event: 'settings.updated',
            subject: $setting,
            oldValues: ['value' => 'USD'],
            newValues: ['value' => 'NGN'],
            tags: ['settings', 'organization'],
            actor: $actor,
        );

        $this->assertTrue($log->exists);
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame(Setting::class, $log->auditable_type);
        $this->assertSame($setting->getKey(), $log->auditable_id);
        $this->assertEquals(['value' => 'USD'], $log->old_values);
        $this->assertEquals(['value' => 'NGN'], $log->new_values);
        $this->assertSame(['settings', 'organization'], $log->tagList());
        $this->assertNotNull($log->created_at, 'An entry with no moment cannot be ordered against anything.');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'settings.updated',
            'user_id' => $actor->id,
            'auditable_type' => Setting::class,
            'auditable_id' => $setting->getKey(),
        ]);
    }

    public function test_the_actor_defaults_to_the_authenticated_user(): void
    {
        $administrator = $this->makeUser();
        $this->actingAs($administrator);

        $log = $this->logger()->record(event: 'settings.viewed');

        $this->assertSame($administrator->id, $log->user_id);
    }

    public function test_a_null_actor_records_a_system_action_rather_than_failing(): void
    {
        // Scheduled jobs and queue workers have nobody authenticated. An entry
        // with no actor is still worth having — it is the platform acting, which
        // is a different and useful thing to record.
        $log = $this->logger()->record(event: 'audit.retention_pruned', tags: ['retention']);

        $this->assertNull($log->user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'audit.retention_pruned', 'user_id' => null]);
    }

    public function test_the_ip_and_user_agent_of_the_request_are_recorded(): void
    {
        Route::middleware('web')->get('/tests/audit-probe', function (): string {
            app(AuditLogger::class)->record(event: 'tests.probed');

            return 'ok';
        });

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Probe/1.0'])
            ->get('/tests/audit-probe')
            ->assertOk();

        $log = AuditLog::query()->ofEvent('tests.probed')->firstOrFail();

        $this->assertSame('203.0.113.7', $log->ip_address);
        $this->assertSame('Probe/1.0', $log->user_agent);
    }

    public function test_an_empty_before_or_after_state_is_stored_as_null(): void
    {
        $created = $this->logger()->record(event: 'settings.created', newValues: ['key' => 'tests.currency']);

        // "There was no previous value" and "the previous value was an empty set"
        // are different claims, and an investigation reads them differently.
        $this->assertNull($created->old_values);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.created', 'old_values' => null]);
    }

    // ── Redaction ───────────────────────────────────────────────────────────

    public function test_secrets_are_redacted_before_they_reach_the_table(): void
    {
        $this->logger()->record(
            event: 'users.credentials_changed',
            oldValues: ['password' => 'old-hunter2', 'name' => 'Amaka'],
            newValues: [
                'password' => 'new-hunter2',
                'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
                'card_number' => '4084084084084081',
                'connection' => ['refresh_token' => 'a-real-refresh-token'],
                'name' => 'Amaka Obi',
            ],
            tags: ['security'],
        );

        // Asserted against the raw column rather than the model, because the claim
        // is about what is AT REST: a redaction that only happened in memory would
        // still pass a model-level assertion.
        $stored = (string) DB::table('audit_logs')->where('event', 'users.credentials_changed')->value('new_values');

        foreach (['new-hunter2', 'JBSWY3DPEHPK3PXP', '4084084084084081', 'a-real-refresh-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, sprintf('"%s" reached the audit table.', $secret));
        }

        $log = AuditLog::query()->ofEvent('users.credentials_changed')->firstOrFail();

        // The key survives: which field changed is part of the record.
        $this->assertSame(SensitiveDataScrubber::REDACTED, $log->new_values['password']);
        $this->assertSame(SensitiveDataScrubber::REDACTED, $log->new_values['two_factor_secret']);
        $this->assertSame(SensitiveDataScrubber::REDACTED, $log->new_values['connection']['refresh_token']);
        $this->assertSame('Amaka Obi', $log->new_values['name'], 'An ordinary value must not be collateral damage.');
        $this->assertSame('Amaka', $log->old_values['name']);
    }

    public function test_a_secret_pasted_into_free_text_does_not_survive_either(): void
    {
        // The dangerous case has no sensitive KEY to match on: a provider key
        // pasted into a support note, or an Authorization header captured into an
        // exception message. Only the value patterns catch these.
        $this->logger()->record(
            event: 'integrations.call_failed',
            newValues: ['message' => 'Paystack rejected sk_test_9a8b7c6d5e4f with 401'],
        );

        $stored = (string) DB::table('audit_logs')->where('event', 'integrations.call_failed')->value('new_values');

        $this->assertStringNotContainsString('sk_test_9a8b7c6d5e4f', $stored);
        $this->assertStringContainsString(SensitiveDataScrubber::REDACTED, $stored);
        $this->assertStringContainsString('Paystack rejected', $stored, 'The rest of the sentence is the evidence.');
    }

    public function test_business_values_are_not_collateral_damage(): void
    {
        $this->logger()->record(
            event: 'grades.scale_updated',
            newValues: [
                'grade_code' => 'A1',
                'coupon_code' => 'SAVE10',
                'country_code' => 'NG',
                'subject_code' => 'MTH-101',
                'score' => 87,
                'platform_fee_percent' => 12.5,
            ],
        );

        $log = AuditLog::query()->ofEvent('grades.scale_updated')->firstOrFail();

        // This is the assertion that keeps the trail useful. Coupons, subjects,
        // grades and countries all have a `code`; a redaction list built from good
        // intentions would have blanked every one of them and nobody would notice
        // until an investigation needed the value.
        $this->assertEquals([
            'grade_code' => 'A1',
            'coupon_code' => 'SAVE10',
            'country_code' => 'NG',
            'subject_code' => 'MTH-101',
            'score' => 87,
            'platform_fee_percent' => 12.5,
        ], $log->new_values);

        // assertEquals on arrays is order-insensitive, which is what a MySQL JSON
        // round trip needs, but it is also loose about type — and a rate that came
        // back as the string "12.5" would be a real defect.
        $this->assertSame(87, $log->new_values['score']);
        $this->assertSame(12.5, $log->new_values['platform_fee_percent']);
    }

    // ── Shape ───────────────────────────────────────────────────────────────

    public function test_tags_are_trimmed_deduplicated_and_read_back_as_a_list(): void
    {
        $log = $this->logger()->record(event: 'settings.updated', tags: ['settings', ' payments ', 'payments']);

        $this->assertSame('settings,payments', $log->tags);
        $this->assertSame(['settings', 'payments'], $log->tagList());
    }

    public function test_a_tag_containing_a_comma_is_refused_and_writes_nothing(): void
    {
        try {
            $this->logger()->record(event: 'settings.updated', tags: ['settings,finance']);

            $this->fail('A comma inside a tag splits into two on read, so tagList() would report a tag nobody wrote.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('comma', $exception->getMessage());
        }

        $this->assertDatabaseMissing('audit_logs', ['event' => 'settings.updated']);
    }

    public function test_an_event_name_too_long_for_the_column_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('80');

        // Well-formed but 88 characters: the length check has to stand on its own,
        // not be reached only because the shape check happened to fail first.
        $this->logger()->record(event: str_repeat('settings.', 9).'updated');
    }

    public function test_an_event_name_that_cannot_be_prefix_searched_is_refused(): void
    {
        foreach (['Settings Updated', 'settings', 'settings.', 'settings.updated at 09:00'] as $event) {
            try {
                $this->logger()->record(event: $event);

                $this->fail(sprintf('"%s" would break the dotted-prefix search AuditLog::scopeOfEventPrefix() relies on.', $event));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('dotted lowercase', $exception->getMessage());
            }
        }
    }

    // ── The transaction guarantee ───────────────────────────────────────────

    public function test_the_entry_and_the_change_it_describes_commit_or_neither_does(): void
    {
        $setting = $this->makeSetting('tests.currency', 'USD', SettingType::Text);

        try {
            DB::transaction(function () use ($setting): void {
                $setting->value = 'NGN';
                $setting->save();

                $this->logger()->record(
                    event: 'settings.updated',
                    subject: $setting,
                    oldValues: ['value' => 'USD'],
                    newValues: ['value' => 'NGN'],
                );

                // Something after the audit write fails, the way a real action
                // does when a later step hits a rule.
                throw new RuntimeException('The step after the audit entry failed.');
            });
        } catch (RuntimeException) {
            // Expected: the caller decides what a failed transaction means.
        }

        // An entry describing a change that never happened is a false record, and
        // a change with no entry is indistinguishable from tampering. Neither
        // state may be reachable, which is why nothing here catches or commits.
        $this->assertDatabaseMissing('audit_logs', ['event' => 'settings.updated']);
        $this->assertSame('USD', $setting->fresh()->typedValue());
    }

    public function test_a_committed_entry_is_part_of_the_record_it_describes(): void
    {
        $setting = $this->makeSetting('tests.currency', 'USD', SettingType::Text);

        DB::transaction(function () use ($setting): void {
            $setting->value = 'NGN';
            $setting->save();

            $this->logger()->record(
                event: 'settings.updated',
                subject: $setting,
                oldValues: ['value' => 'USD'],
                newValues: ['value' => 'NGN'],
            );
        });

        $log = AuditLog::query()->ofEvent('settings.updated')->firstOrFail();

        $this->assertSame('NGN', $setting->fresh()->typedValue());
        $this->assertSame($setting->getKey(), $log->auditable_id);
        $this->assertEquals(['value' => 'USD'], $log->old_values);
        $this->assertEquals(['value' => 'NGN'], $log->new_values);
    }
}
