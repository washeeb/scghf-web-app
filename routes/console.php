<?php

declare(strict_types=1);

use App\Models\ErrorReport;
use App\Support\SiteHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
 * The shop sweep, hourly.
 *
 * Stock held by a checkout somebody walked away from goes back on the shelf
 * an hour later rather than at tomorrow's reconciliation — twelve mugs and
 * eleven abandoned checkouts must not read as sold out all day. Expired
 * baskets are deleted in the same pass. `--execute`, because it is a sweep
 * with a verify step and nothing it does is destructive of money.
 */
Schedule::command('scghf:sweep-shop --execute')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * What has run low, once a morning, to the shop email — each item once until
 * it is restocked. `--execute` because an alert is not destructive.
 */
Schedule::command('scghf:stock-alerts --execute')
    ->dailyAt('07:00')
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

/*
 * Verify that images marked sanitised really are, weekly.
 *
 * New uploads are stripped on the way in and the backfill handles the rest, so
 * this is the check rather than the work: `metadata_stripped_at` records that
 * the sanitiser RAN, and this asks whether it WORKED. Exits non-zero on any
 * file still carrying metadata, which is what makes cron email somebody.
 */
Schedule::command('scghf:strip-media-metadata --execute --verify')
    ->weeklyOn(2, '04:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Archive a closed year of the audit trail, once a year.
 *
 * DRY BY DEFAULT, deliberately. It removes rows from a table whose whole value
 * is that rows are never removed from it — so the scheduled run reports what
 * WOULD move and a person runs it for real, having read that report. Automating
 * the destruction as well as the detection is a step this project has not taken
 * anywhere else either.
 */
Schedule::command('scghf:archive-audit-log')
    ->yearlyOn(2, 1, '03:00')
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| ⚠ These lines are why `backup_log` was always empty.
|
| `spatie/laravel-backup` was installed in Phase 2 and `RecordBackupOutcome` was
| registered as a listener in Phase 3, so the table, the model, the policy and
| the listener all existed — waiting for events that nothing ever fired. Site
| Health asks when a backup last succeeded, and that is a question worth asking
| only if something is answering it.
|
| Early, and before the account wakes up. The archive is written to local disk
| on the same quota as the website, and a foundation's staff arriving to a site
| that is briefly slow is better than one that is briefly slow at midday.
*/
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    /*
     * `BACKUP_ENABLED` was documented in `.env.example` from Phase 2 and read
     * by nothing. It is read here — the one switch that turns the whole thing
     * off, for a local machine where a nightly zip of the whole application is
     * pointless.
     */
    ->when(fn (): bool => (bool) env('BACKUP_ENABLED', true));

/*
 * Prune old archives an hour later, not in the same tick.
 *
 * Cleanup deletes by age and by total size, and running it immediately after a
 * backup that is still being written is how a fresh archive gets counted, found
 * to breach the size ceiling, and removed. The gap is deliberate.
 */
Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->when(fn (): bool => (bool) env('BACKUP_ENABLED', true));

/*
|--------------------------------------------------------------------------
| The scheduler's own heartbeat
|--------------------------------------------------------------------------
|
| One cache write a minute, and the only way to answer "is cron running?".
|
| Everything above fails SILENTLY when the cron line is missing: no error is
| logged, because nothing tried and failed — nothing tried at all. Recurring
| gifts are not charged, the outbox does not drain, receipts are never sent, and
| the application looks completely healthy from the inside.
|
| A stale timestamp is the difference between that and a working site, and Site
| Health reads it.
*/
Schedule::call(fn () => Cache::forever(SiteHealth::HEARTBEAT_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat')
    ->withoutOverlapping();
