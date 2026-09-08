<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Console commands & scheduled tasks
|--------------------------------------------------------------------------
|
| On shared cPanel hosting there is no supervisor and no long-running worker.
| Everything asynchronous is driven by ONE cron entry:
|
|   * * * * * cd /home/<user>/<app> && php artisan schedule:run >> /dev/null 2>&1
|
| plus a per-minute queue drain (see deploy/cpanel/crontab.txt). Both are
| required: `schedule:run` alone will enqueue work that nothing ever processes,
| which is how a payment webhook silently stops completing an order.
|
| Every task here must be idempotent. On shared hosting a cron run can overlap
| its predecessor when the host is slow, so `withoutOverlapping()` guards the
| long ones and no task assumes it is the only instance running.
|
| `runInBackground()` is deliberately NOT used: it needs proc_open(), which is
| on the disabled-functions list of most shared PHP hosting. Every task here
| runs inline and is short enough to finish inside a one-minute cron window.
|
*/

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Housekeeping
|--------------------------------------------------------------------------
*/

// Expired sessions. Retained rather than truncated: the `sessions` table also
// backs "sign out my other devices", so rows are pruned by age only.
Schedule::command('session:prune-expired --hours=48')
    ->daily()
    ->withoutOverlapping();

// Failed jobs are kept for a week so an operator can inspect and retry a batch
// that failed during a provider outage, then removed — they are evidence, not
// a permanent archive.
Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Queue drain for hosts without a persistent worker
|--------------------------------------------------------------------------
|
| `queue:work --stop-when-empty` exits once the queue is drained, which makes it
| safe to run from cron every minute on a host that will kill a long-lived
| process. The dedicated cron entry in deploy/cpanel/crontab.txt is the primary
| mechanism; this is the fallback for hosts that only allow one cron line.
|
*/
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=90')
    ->everyMinute()
    ->withoutOverlapping(2);

/*
|--------------------------------------------------------------------------
| Deployment self-check
|--------------------------------------------------------------------------
|
| Runs after every scheduled cycle so a misconfiguration surfaces in the log
| rather than in a support ticket. Failure here does not stop other tasks.
|
*/
Schedule::command('platform:doctor --skip-database')
    ->dailyAt('03:15')
    ->withoutOverlapping();
