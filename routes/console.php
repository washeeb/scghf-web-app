<?php

declare(strict_types=1);

use App\Models\ErrorReport;
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

/*
 * The outbox, every minute.
 *
 * Every minute rather than every five because the host's cap is per HOUR: a
 * frequent, small drain spreads the same allowance evenly instead of sending
 * two hundred messages in one burst and nothing for the next fifty-nine
 * minutes. Bursts are what trip shared-hosting rate limiters.
 *
 * The command sends only what the throttle allows and stops, so overlapping
 * runs would be harmless — but `withoutOverlapping()` keeps the process count
 * down on an account with an entry-process limit.
 */
Schedule::command('scghf:send-messages')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * SMS delivery reports, hourly.
 *
 * Hourly rather than every minute because a network takes minutes to report,
 * and because this is the only way to detect a sender ID that has stopped being
 * accepted — a failure that is completely invisible otherwise: mNotify accepts
 * every message, the networks drop every message, and nothing anywhere reports
 * an error. Exits non-zero when the delivery rate collapses, which is what
 * makes cron email somebody.
 */
Schedule::command('scghf:sms-delivery-reports')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Verify the audit trail, daily.
 *
 * Quiet when clean, so cron only emails when something is wrong. Each clean run
 * also anchors the head hash to the application log — which is what turns the
 * chain from a proof of internal consistency (which somebody who rewrote the
 * whole chain would also have) into something an auditor can actually check
 * against a value recorded outside the database.
 */
Schedule::command('scghf:verify-audit-log --quiet-when-clean')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Trim the error table back if it has grown past its ceiling.
 *
 * Resolved groups go first, then muted, then the oldest untouched ones — a
 * safety valve for the shared-hosting inode quota, not a retention policy,
 * which is why it prefers to delete what somebody has already dealt with.
 */
Schedule::call(fn () => ErrorReport::pruneToCeiling())
    ->dailyAt('04:00')
    ->name('prune-error-reports')
    ->onOneServer();
