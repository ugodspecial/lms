<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Administration\Models\AuditLog;
use App\Domain\Administration\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Removes audit entries older than the retention window (§56, docs/04 §11).
 *
 * This is the ONLY thing on the platform that removes audit history, and the way
 * it does it is the point. `AuditLog::delete()` throws, and so does `save()` on an
 * existing row, because the only reason to remove one record is to change what
 * history says happened. What stays open is a bulk delete through the query
 * builder: it never instantiates a model, so it never reaches that guard. Removing
 * an old RANGE on a schedule is a retention policy; removing one record is
 * tampering, and only the first is permitted.
 *
 * The window comes from `config('platform.logging.retention_days.audit')` rather
 * than a default here, because how long a platform keeps its compliance trail is a
 * decision an operator makes about their own jurisdiction (§82: nothing
 * business-shaped is hard-coded). `--days` overrides it for a one-off cleanup and
 * is the reason a deploy does not have to change config to empty a table before a
 * migration.
 *
 * The prune is itself audited. A trail that shrinks with no record of it is
 * indistinguishable from one that was edited, so the entry says how many rows went
 * and what the window was — which is also what makes the schedule auditable: if the
 * window is ever shortened, the change shows up in the trail it affected.
 *
 * Idempotent and safe to overlap: it deletes by a cutoff computed at run time, so a
 * second run in the same window finds nothing. The schedule still wraps it in
 * `withoutOverlapping()`, because a large delete on shared hosting is slow and two
 * of them compete for the same locks.
 */
final class AuditPrune extends Command
{
    protected $signature = 'audit:prune
                            {--days= : Override the configured retention window}
                            {--dry-run : Report what would be removed, and remove nothing}';

    protected $description = 'Remove audit entries older than the retention window';

    public function handle(AuditLogger $audit): int
    {
        $configured = (int) config('platform.logging.retention_days.audit', 365);
        $days = $this->option('days') === null ? $configured : (int) $this->option('days');

        if ($days < 1) {
            $this->components->error('The retention window must be at least one day.');

            return self::FAILURE;
        }

        // `config('app.timezone')` is UTC, and so is the column, so this comparison
        // is between two UTC instants. A cutoff computed in a display timezone would
        // keep or drop an hour of history depending on where the operator is.
        $cutoff = now()->subDays($days);

        $candidates = AuditLog::query()->where('created_at', '<', $cutoff);
        $count = (clone $candidates)->count();

        if ($count === 0) {
            $this->components->info(sprintf('No audit entries are older than %d days.', $days));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->components->warn(sprintf(
                '%s audit %s older than %s would be removed. Nothing was deleted.',
                number_format($count),
                $count === 1 ? 'entry' : 'entries',
                $cutoff->toDateTimeString(),
            ));

            return self::SUCCESS;
        }

        // Bulk, on purpose: see the class docblock. This line is the reason
        // AuditLog::delete() is allowed to throw. The builder's own delete()
        // returns mixed, and the count is reported and audited as a number.
        $deleted = (int) $candidates->delete();

        $audit->record(
            event: 'audit.retention_pruned',
            newValues: [
                'deleted' => $deleted,
                'retention_days' => $days,
                'older_than' => $cutoff->toDateTimeString(),
            ],
            tags: ['retention', 'audit'],
        );

        $this->components->info(sprintf(
            'Removed %s audit %s older than %s (retention: %d days).',
            number_format($deleted),
            $deleted === 1 ? 'entry' : 'entries',
            $cutoff->toDateTimeString(),
            $days,
        ));

        return self::SUCCESS;
    }
}
