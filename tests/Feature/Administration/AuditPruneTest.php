<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Domain\Administration\Models\AuditLog;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsPhaseOneRecords;
use Tests\TestCase;

/**
 * Audit retention (§56, docs/04 §11): the trail has to be complete for as long as
 * the platform is obliged to keep it, and no longer, on a database that shared
 * hosting has to back up.
 *
 * `AdministrationPersistenceTest` proves the model refuses to lose one entry and
 * that a bulk range delete still works. This proves the COMMAND is the thing that
 * uses it, and that it behaves like an operator tool rather than a delete button:
 *
 * • the window is config, because how long a platform keeps its compliance trail
 *   is a decision about its jurisdiction, not a constant in the code (§82);
 * • `--days` overrides it for a one-off, and a window under one day is refused
 *   rather than interpreted as "everything";
 * • `--dry-run` reports and removes nothing, because the first time anybody runs
 *   this on a real database is not the time to discover what the window was;
 * • the prune is itself recorded, since a trail that shrinks with no note of it is
 *   indistinguishable from a trail that was edited;
 * • it is scheduled, because retention that depends on somebody remembering is not
 *   retention.
 *
 * Assertions are against database state and exit codes rather than console text:
 * the text is what a person reads, the state is what a backup depends on.
 */
final class AuditPruneTest extends TestCase
{
    use BuildsPhaseOneRecords, RefreshDatabase;

    public function test_entries_older_than_the_configured_window_are_removed_and_recent_ones_stay(): void
    {
        config(['platform.logging.retention_days.audit' => 365]);

        $ancient = $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subDays(400)]);
        $lastYear = $this->makeAuditLog('settings.updated', overrides: ['created_at' => Carbon::now()->subDays(366)]);
        $recent = $this->makeAuditLog('files.uploaded', overrides: ['created_at' => Carbon::now()->subDays(10)]);
        $today = $this->makeAuditLog('users.status_changed');

        $this->artisan('audit:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $ancient->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $lastYear->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $today->id]);
    }

    public function test_the_range_is_chosen_by_time_only_not_by_event(): void
    {
        config(['platform.logging.retention_days.audit' => 30]);

        // Retention is a policy about a range of time. A prune that kept certain
        // kinds of entry would be a policy nobody wrote down, and the entries it
        // kept would be the ones somebody thought were important at the time.
        $oldPayment = $this->makeAuditLog('payments.verified', overrides: ['created_at' => Carbon::now()->subDays(45)]);
        $oldSecurity = $this->makeAuditLog('auth.login_failed', overrides: ['created_at' => Carbon::now()->subDays(45)]);
        $newPayment = $this->makeAuditLog('payments.verified', overrides: ['created_at' => Carbon::now()->subDays(5)]);

        $this->artisan('audit:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $oldPayment->id]);
        $this->assertDatabaseMissing('audit_logs', ['id' => $oldSecurity->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $newPayment->id]);
    }

    public function test_the_window_comes_from_config(): void
    {
        $entry = $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subDays(40)]);

        // Longer than the entry's age: nothing goes.
        config(['platform.logging.retention_days.audit' => 365]);
        $this->artisan('audit:prune')->assertExitCode(0);
        $this->assertDatabaseHas('audit_logs', ['id' => $entry->id]);

        // Shorter than the entry's age: it goes. Same command, no arguments.
        config(['platform.logging.retention_days.audit' => 30]);
        $this->artisan('audit:prune')->assertExitCode(0);
        $this->assertDatabaseMissing('audit_logs', ['id' => $entry->id]);
    }

    public function test_the_days_option_overrides_the_configured_window(): void
    {
        config(['platform.logging.retention_days.audit' => 365]);

        $old = $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subDays(3)]);
        $newer = $this->makeAuditLog('orders.created');

        $this->artisan('audit:prune', ['--days' => 1])->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $newer->id]);
    }

    public function test_a_window_under_one_day_is_refused_rather_than_interpreted(): void
    {
        $entry = $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subYears(3)]);

        foreach (['0', '-1', '-400'] as $days) {
            $this->artisan('audit:prune', ['--days' => $days])->assertFailed();

            // `--days=0` reading as "everything" would empty the compliance trail on
            // a typo, and the command would report success while doing it.
            $this->assertDatabaseHas('audit_logs', ['id' => $entry->id]);
        }
    }

    public function test_a_dry_run_reports_and_removes_nothing(): void
    {
        config(['platform.logging.retention_days.audit' => 30]);

        $old = $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subYears(2)]);
        $before = AuditLog::ofEvent('audit.retention_pruned')->count();

        $this->artisan('audit:prune', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', ['id' => $old->id]);

        // A dry run is a question, not an action, so it writes nothing — not even
        // the entry that says a prune happened.
        $this->assertSame($before, AuditLog::ofEvent('audit.retention_pruned')->count());
    }

    public function test_the_prune_is_recorded_in_the_trail_it_changed(): void
    {
        config(['platform.logging.retention_days.audit' => 30]);

        $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subYears(2)]);
        $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subMonths(3)]);
        $this->makeAuditLog('orders.created');

        $this->artisan('audit:prune')->assertExitCode(0);

        $entry = AuditLog::ofEvent('audit.retention_pruned')->latest('id')->first();

        $this->assertNotNull($entry, 'a trail that shrinks with no note of it is indistinguishable from one that was edited');
        $this->assertEquals([
            'deleted' => 2,
            'retention_days' => 30,
        ], array_intersect_key((array) $entry->new_values, ['deleted' => true, 'retention_days' => true]));
        $this->assertContains('retention', $entry->tagList());

        // The entry that records the prune is itself new, so a later run keeps it.
        $this->assertTrue(Carbon::parse($entry->created_at)->greaterThan(Carbon::now()->subDays(30)));
    }

    public function test_running_it_again_is_harmless(): void
    {
        config(['platform.logging.retention_days.audit' => 30]);

        $this->makeAuditLog('orders.created', overrides: ['created_at' => Carbon::now()->subYears(2)]);
        $before = AuditLog::ofEvent('audit.retention_pruned')->count();

        $this->artisan('audit:prune')->assertExitCode(0);
        $this->artisan('audit:prune')->assertExitCode(0);

        // The cutoff is computed at run time, so a second run finds nothing and says
        // so without writing another entry. A schedule that runs daily must not add
        // a row a day saying that it did nothing.
        $this->assertSame($before + 1, AuditLog::ofEvent('audit.retention_pruned')->count());
    }

    public function test_the_command_is_scheduled_so_retention_does_not_depend_on_anybody_remembering(): void
    {
        // Resolving the console kernel is what loads routes/console.php, and it is
        // what `php artisan schedule:run` does on the host's cron.
        $this->artisan('audit:prune', ['--dry-run' => true])->assertExitCode(0);

        $commands = collect(app(Schedule::class)->events())
            ->map(static fn (Event $event): string => (string) $event->command)
            ->all();

        $this->assertNotEmpty(
            array_filter($commands, static fn (string $command): bool => str_contains($command, 'audit:prune')),
            'audit:prune is not on the schedule, so the table grows until somebody notices',
        );
    }
}
