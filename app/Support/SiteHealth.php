<?php

declare(strict_types=1);

namespace App\Support;

use App\Communications\Contracts\ReportsBalance;
use App\Communications\Contracts\SmsGateway;
use App\Models\BackupLogEntry;
use App\Models\Media;
use App\Models\ThemeSetting;
use App\Providers\CommunicationServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is this site actually working?
 *
 * ── Why this page exists ────────────────────────────────────────────────────
 *
 * Everything here fails silently on shared hosting. A cron line that was never
 * added means the queue never drains: donation receipts sit in a table and no
 * error is raised anywhere, because nothing tried and failed — nothing tried at
 * all. A backup that stopped running three weeks ago looks exactly like one
 * that runs fine. Pre-paid SMS credits reach zero and receipts stop, quietly.
 *
 * None of those produce a log line. All of them are answerable in one query.
 *
 * ── Every check is wrapped ──────────────────────────────────────────────────
 *
 * This page is opened when something is already wrong. A check that throws
 * would take down the one screen somebody came to for an explanation, so each
 * returns "cannot tell" instead — which is a real answer, and a different one
 * from "fine".
 *
 * ── Nothing here trusts a green light it did not earn ───────────────────────
 *
 * The queue check reports "cannot tell" when the queue is empty, because an
 * empty queue is what both a working worker and an absent one look like. The
 * scheduler check reports on a heartbeat the scheduler itself writes, so a
 * missing cron line shows as a stale timestamp rather than as silence.
 */
class SiteHealth
{
    /** Where the scheduler stamps that it ran. */
    public const HEARTBEAT_KEY = 'scghf.heartbeat.scheduler';

    /**
     * A job sitting unclaimed for longer than this means nothing is working it.
     *
     * The cron line runs `queue:work --stop-when-empty --max-time=55` every
     * minute, so a job should be picked up within about a minute. Five is
     * generous enough to survive a slow tick and short enough to catch a worker
     * that never runs.
     */
    private const QUEUE_STALE_MINUTES = 5;

    /** @return Collection<int, HealthCheck> */
    public function checks(): Collection
    {
        return collect([
            $this->wrap('environment', __('Environment'), fn () => $this->environment()),
            $this->wrap('scheduler', __('Scheduled jobs'), fn () => $this->scheduler()),
            $this->wrap('queue', __('Background queue'), fn () => $this->queue()),
            $this->wrap('failed_jobs', __('Failed jobs'), fn () => $this->failedJobs()),
            $this->wrap('storage', __('File storage'), fn () => $this->storage()),
            $this->wrap('backup', __('Backups'), fn () => $this->backup()),
            $this->wrap('payments', __('Paystack'), fn () => $this->payments()),
            $this->wrap('sms', __('SMS credits'), fn () => $this->sms()),
            $this->wrap('https', __('Secure connection'), fn () => $this->https()),
            $this->wrap('indexing', __('Search engines'), fn () => $this->indexing()),
            $this->wrap('settings', __('Site settings'), fn () => $this->settings()),
            $this->wrap('contrast', __('Colour contrast'), fn () => $this->contrast()),
            $this->wrap('media', __('Image metadata'), fn () => $this->media()),
        ]);
    }

    /** @return Collection<int, HealthCheck> */
    public function problems(): Collection
    {
        return $this->checks()->filter(fn (HealthCheck $check): bool => $check->isProblem())->values();
    }

    // ── The checks ──────────────────────────────────────────────────────────

    /**
     * Production, with debug off.
     *
     * `APP_DEBUG=true` on a live site prints the stack trace — file paths,
     * environment variables, fragments of query — to whoever triggered the
     * error. On a site taking card payments it is the single most damaging
     * one-line misconfiguration available.
     */
    private function environment(): HealthCheck
    {
        $env = (string) app()->environment();
        $debug = (bool) config('app.debug');

        if (app()->isProduction() && $debug) {
            return HealthCheck::critical('environment', __('Environment'), __('Production, debug ON'),
                __('Set APP_DEBUG=false in .env immediately. With it on, an error shows visitors the '
                    .'server\'s file paths and configuration.'));
        }

        return HealthCheck::ok('environment', __('Environment'), $env.($debug ? ' · '.__('debug on') : ''));
    }

    /**
     * Has `schedule:run` ticked recently?
     *
     * The heartbeat is written by the scheduler itself, once a minute. A stale
     * timestamp means the cron line is missing or the account's cron is off —
     * and everything scheduled has silently stopped: recurring gifts, the
     * outbox, reconciliation, the retention sweep, the audit chain check.
     */
    private function scheduler(): HealthCheck
    {
        $last = Cache::get(self::HEARTBEAT_KEY);

        if ($last === null) {
            return HealthCheck::critical('scheduler', __('Scheduled jobs'), __('Never run'),
                __('Add the cron line: * * * * * php /home/USER/app/artisan schedule:run. Without it '
                    .'recurring gifts are not charged, queued email is not sent, and nothing that '
                    .'runs on a timer runs at all.'));
        }

        $at = Carbon::parse((string) $last);

        if ($at->lt(now()->subMinutes(15))) {
            return HealthCheck::critical('scheduler', __('Scheduled jobs'),
                __('Last ran :when', ['when' => $at->diffForHumans()]),
                __('The scheduler has stopped. Check the cron entry for schedule:run in cPanel.'));
        }

        return HealthCheck::ok('scheduler', __('Scheduled jobs'), __('Ran :when', ['when' => $at->diffForHumans()]));
    }

    /**
     * Is anything working the queue?
     *
     * ⚠ An empty queue is NOT proof the worker is running — it is exactly what
     * a working worker and a missing cron line both look like. So an empty
     * queue reports "cannot tell" rather than green. The honest signal is a job
     * that has been sitting unclaimed.
     */
    private function queue(): HealthCheck
    {
        $pending = DB::table('jobs')->count();

        if ($pending === 0) {
            return HealthCheck::unknown('queue', __('Background queue'), __('Nothing waiting'),
                __('An empty queue looks the same whether the worker is running or not. The scheduled '
                    .'jobs check above is the one that tells you cron is alive.'));
        }

        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');

        if ($oldest !== null && Carbon::createFromTimestamp((int) $oldest)->lt(now()->subMinutes(self::QUEUE_STALE_MINUTES))) {
            return HealthCheck::critical('queue', __('Background queue'),
                trans_choice('{1}:count job waiting|[2,*]:count jobs waiting', $pending, ['count' => $pending]),
                __('Jobs are queued and nothing is picking them up. Add the cron line: * * * * * php '
                    .'/home/USER/app/artisan queue:work --stop-when-empty --max-time=55 --tries=3. '
                    .'Donation receipts are among what is waiting.'));
        }

        return HealthCheck::ok('queue', __('Background queue'),
            trans_choice('{1}:count job waiting|[2,*]:count jobs waiting', $pending, ['count' => $pending]));
    }

    private function failedJobs(): HealthCheck
    {
        $failed = DB::table('failed_jobs')->count();

        if ($failed === 0) {
            return HealthCheck::ok('failed_jobs', __('Failed jobs'), __('None'));
        }

        $latest = DB::table('failed_jobs')->max('failed_at');

        return HealthCheck::warning('failed_jobs', __('Failed jobs'),
            trans_choice('{1}:count failed|[2,*]:count failed', $failed, ['count' => $failed]),
            __('The most recent failed :when. A failed job is work that was meant to happen and did '
                .'not — an email nobody received, a receipt nobody got. Run php artisan queue:retry all '
                .'once the cause is fixed.', [
                    'when' => $latest === null ? __('at some point') : Carbon::parse((string) $latest)->diffForHumans(),
                ]));
    }

    /**
     * Can the application write where it needs to?
     *
     * A read-only `storage/` after a deploy that reset permissions is a site
     * that cannot cache a view, write a log, or save an upload — and the first
     * symptom is usually a blank page.
     */
    private function storage(): HealthCheck
    {
        $paths = [
            storage_path('app'),
            storage_path('framework'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        $unwritable = collect($paths)->reject(fn (string $path): bool => is_writable($path))->values();

        if ($unwritable->isNotEmpty()) {
            return HealthCheck::critical('storage', __('File storage'),
                trans_choice('{1}:count folder not writable|[2,*]:count folders not writable',
                    $unwritable->count(), ['count' => $unwritable->count()]),
                __('Not writable: :paths. Fix the permissions in cPanel\'s File Manager (755 on the '
                    .'folders).', ['paths' => $unwritable->implode(', ')]));
        }

        return HealthCheck::ok('storage', __('File storage'), __('Writable'));
    }

    /**
     * When did a backup last succeed?
     *
     * The package emails on failure, which is right and is not enough: an email
     * is read once by whoever happened to open it, and a backup that has been
     * failing for three weeks looks exactly like one nobody is emailing about.
     */
    private function backup(): HealthCheck
    {
        $last = BackupLogEntry::query()
            ->where('type', BackupLogEntry::TYPE_BACKUP)
            ->where('status', BackupLogEntry::STATUS_COMPLETED)
            ->latest('id')
            ->first();

        if ($last === null) {
            return HealthCheck::critical('backup', __('Backups'), __('Never'),
                __('No backup has ever completed. backup:run is scheduled daily at 02:00, so this means '
                    .'either the scheduler is not running — check above — or the destination in '
                    .'config/backup.php is not writable. A site with no backup is one bad afternoon '
                    .'away from starting again.'));
        }

        $at = $last->created_at;

        if ($at?->lt(now()->subDays(2))) {
            return HealthCheck::warning('backup', __('Backups'),
                __('Last :when', ['when' => $at->diffForHumans()]),
                __('Backups have stopped. They are scheduled daily, so more than two days means '
                    .'something is failing — check the scheduled jobs above first.'));
        }

        return HealthCheck::ok('backup', __('Backups'), __('Last :when · :size', [
            'when' => $at?->diffForHumans() ?? __('unknown'),
            'size' => $last->humanSize() ?? __('size unknown'),
        ]));
    }

    /**
     * Live keys or test keys?
     *
     * Both directions are a real failure. Test keys on a live site take no
     * money and tell the donor it worked. Live keys on staging take real money
     * from whoever is testing.
     */
    private function payments(): HealthCheck
    {
        $secret = (string) config('payments.paystack.secret_key', '');
        $driver = (string) config('payments.driver', 'fake');

        if ($driver !== 'paystack') {
            return HealthCheck::warning('payments', __('Paystack'), __('Not connected (:driver)', ['driver' => $driver]),
                __('The payment driver is not Paystack, so no real payment can be taken. Set '
                    .'PAYMENTS_DRIVER=paystack and the live keys before launch.'));
        }

        if (blank($secret) || str_contains($secret, '{{')) {
            return HealthCheck::critical('payments', __('Paystack'), __('No key set'),
                __('PAYSTACK_SECRET_KEY is empty or still a placeholder. Nobody can donate.'));
        }

        $isLive = str_starts_with($secret, 'sk_live_');

        if (app()->isProduction() && ! $isLive) {
            return HealthCheck::critical('payments', __('Paystack'), __('TEST keys on production'),
                __('The live site is using Paystack test keys. Donations appear to succeed and no '
                    .'money arrives.'));
        }

        if (! app()->isProduction() && $isLive) {
            return HealthCheck::critical('payments', __('Paystack'), __('LIVE keys off production'),
                __('This is not the production site and it is holding live Paystack keys. Anybody '
                    .'testing the donation form is spending real money.'));
        }

        return HealthCheck::ok('payments', __('Paystack'), $isLive ? __('Live keys') : __('Test keys'));
    }

    /**
     * Pre-paid SMS credits.
     *
     * `MnotifyGateway::balance()` was written in Phase 3 and read by nothing.
     * Credits run out silently, and the first sign is a week of receipts that
     * never went.
     */
    private function sms(): HealthCheck
    {
        if (! config('communications.channels.sms', true)) {
            return HealthCheck::ok('sms', __('SMS credits'), __('SMS is switched off'));
        }

        $driver = CommunicationServiceProvider::smsDriver();

        if ($driver === 'log') {
            return HealthCheck::ok('sms', __('SMS credits'), __('Not sending real messages (log)'));
        }

        $gateway = app(SmsGateway::class);

        if (! $gateway instanceof ReportsBalance) {
            return HealthCheck::ok('sms', __('SMS credits'), __(':driver does not report a balance; check the provider dashboard', ['driver' => $driver]));
        }

        $balance = $gateway->balance();

        if ($balance === null) {
            return HealthCheck::unknown('sms', __('SMS credits'), __('Could not be read'),
                __('The balance request to :driver failed. That is not the same as having no credits '
                    .'— check the API key and whether the service is reachable.', ['driver' => $driver]));
        }

        if ($balance->isLow((float) config('communications.sms.low_balance', 50))) {
            return HealthCheck::warning('sms', __('SMS credits'), $balance->format(),
                __('Top up before they run out. When they do, messages stop with no error anywhere.'));
        }

        return HealthCheck::ok('sms', __('SMS credits'), $balance->format());
    }

    private function https(): HealthCheck
    {
        $url = (string) config('app.url');

        if (! str_starts_with($url, 'https://')) {
            return app()->isProduction()
                ? HealthCheck::critical('https', __('Secure connection'), __('APP_URL is not https'),
                    __('A donation form served over http exposes everything typed into it, and most '
                        .'browsers will warn the donor before it loads. Install the free AutoSSL '
                        .'certificate in cPanel and set APP_URL to the https address.'))
                : HealthCheck::warning('https', __('Secure connection'), __('APP_URL is not https'),
                    __('Fine locally. It must be https before this configuration reaches production.'));
        }

        return HealthCheck::ok('https', __('Secure connection'), __('https'));
    }

    /**
     * Is the right site the one search engines can see?
     *
     * Both mistakes are expensive and neither announces itself. Staging indexed
     * alongside production splits the ranking and shows donors a test site;
     * production left noindexed after launch is invisible.
     */
    private function indexing(): HealthCheck
    {
        $allowed = (bool) setting('seo.allow_indexing', false);

        if (app()->isProduction() && ! $allowed) {
            return HealthCheck::warning('indexing', __('Search engines'), __('Blocked'),
                __('The live site is telling search engines not to index it. Turn on "Allow search '
                    .'engine indexing" under Site settings → Search engines once you are ready to be '
                    .'found.'));
        }

        if (! app()->isProduction() && $allowed) {
            return HealthCheck::critical('indexing', __('Search engines'), __('Allowed off production'),
                __('A staging site indexed alongside the real one splits its search ranking and shows '
                    .'donors a test site. Switch indexing off here.'));
        }

        return HealthCheck::ok('indexing', __('Search engines'), $allowed ? __('Allowed') : __('Blocked'));
    }

    private function settings(): HealthCheck
    {
        $unfilled = app(Settings::class)->unfilled()->count();

        if ($unfilled === 0) {
            return HealthCheck::ok('settings', __('Site settings'), __('All filled in'));
        }

        return HealthCheck::warning('settings', __('Site settings'),
            trans_choice('{1}:count still a placeholder|[2,*]:count still placeholders', $unfilled, ['count' => $unfilled]),
            __('Some of these appear on receipts and in the footer — the registration number and the '
                .'TIN among them. Site settings lists which.'));
    }

    private function contrast(): HealthCheck
    {
        $failing = ThemeSetting::query()
            ->withContrastObligation()
            ->get()
            ->reject(fn (ThemeSetting $token): bool => $token->meetsContrast())
            ->count();

        if ($failing === 0) {
            return HealthCheck::ok('contrast', __('Colour contrast'), __('Passes WCAG AA'));
        }

        return HealthCheck::warning('contrast', __('Colour contrast'),
            trans_choice('{1}:count colour fails|[2,*]:count colours fail', $failing, ['count' => $failing]),
            __('Every one of these is text somebody cannot read. The Theme screen shows which, and '
                .'offers a legible replacement.'));
    }

    /**
     * Images still carrying their camera metadata.
     *
     * A photograph taken on a phone outside a beneficiary's home carries the
     * coordinates of it. The sweep runs nightly; a growing backlog means it is
     * not running.
     */
    private function media(): HealthCheck
    {
        $unsanitised = Media::query()->unsanitised()->count();

        if ($unsanitised === 0) {
            return HealthCheck::ok('media', __('Image metadata'), __('All stripped'));
        }

        return HealthCheck::warning('media', __('Image metadata'),
            trans_choice('{1}:count image not checked|[2,*]:count images not checked', $unsanitised, ['count' => $unsanitised]),
            __('None of these can be published until their metadata is removed, which the nightly '
                .'sweep does. A backlog that is not shrinking means the scheduler has stopped.'));
    }

    // ── Nothing here may throw ──────────────────────────────────────────────

    /**
     * @param  callable(): HealthCheck  $check
     */
    private function wrap(string $key, string $label, callable $check): HealthCheck
    {
        try {
            return $check();
        } catch (Throwable $e) {
            /*
             * This page is opened when something is already wrong. A check that
             * throws would take down the one screen somebody came to for an
             * explanation — so the failure becomes the answer.
             */
            return HealthCheck::unknown($key, $label, __('Check failed'), $e->getMessage());
        }
    }
}
