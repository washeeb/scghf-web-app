<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| One cron line runs `schedule:run` every minute; Laravel decides what is
| actually due. Everything recurring is registered here rather than as its own
| cPanel cron entry — the account has an entry-process limit, and a list of
| jobs living in git is a list somebody can review.
|
| `withoutOverlapping()` on all of them because CloudLinux can pause a process
| mid-run, and the next minute's tick would otherwise start a second copy.
|
*/

/*
 * Recurring gifts, once a day, in the morning.
 *
 * In the morning on purpose: a failure is noticed during working hours rather
 * than at 3am, and a donor whose card was declined can be contacted the same
 * day. Safe to run twice — the unique index on (subscription_id, scheduled_on)
 * makes an overlapping run a no-op rather than a double charge.
 */
Schedule::command('scghf:charge-recurring --execute')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Reconciliation, half an hour later, so it sees the morning's charges.
 *
 * Takes no money — it asks the gateway what it already knows. Exits non-zero
 * when something needs a person, which is what makes cron email the
 * administrator; a report nobody reads is the same as no report.
 */
Schedule::command('scghf:reconcile-payments --execute')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The retention sweep, weekly and DRY BY DEFAULT.
 *
 * Deliberately not `--execute`. This destroys records about vulnerable people,
 * and it runs unattended on a schedule — so the scheduled run reports what
 * WOULD go and a person runs it for real. Automating the destruction as well
 * as the detection is a step this project has not taken on purpose.
 */
Schedule::command('scghf:retention')
    ->weeklyOn(1, '07:00')
    ->withoutOverlapping()
    ->onOneServer();
