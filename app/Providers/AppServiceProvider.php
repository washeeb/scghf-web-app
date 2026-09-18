<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\TwoFactorChallengeFailed;
use App\Http\Middleware\CountVisit;
use App\Listeners\AttachGuestCart;
use App\Listeners\RecordAuthenticationEvent;
use App\Listeners\RecordBackupOutcome;
use App\Listeners\SanitiseUploadedImage;
use App\Media\ImageToolchain;
use App\Media\MediaLibrary;
use App\Media\MediaUsage;
use App\Media\UploadPolicy;
use App\Models\Announcement;
use App\Models\Beneficiary;
use App\Models\BeneficiaryDocument;
use App\Models\BlogCategory;
use App\Models\Cause;
use App\Models\CauseUpdate;
use App\Models\Division;
use App\Models\Document;
use App\Models\Donation;
use App\Models\EmailLog;
use App\Models\EventRegistration;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\FocusArea;
use App\Models\Gallery;
use App\Models\ImpactMetric;
use App\Models\Media;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Office;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Partner;
use App\Models\Post;
use App\Models\PrayerRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\Redirect;
use App\Models\SmsLog;
use App\Models\Story;
use App\Models\Tag;
use App\Models\TeamDepartment;
use App\Models\TeamMember;
use App\Models\Testimonial;
use App\Models\ThemeSetting;
use App\Models\Volunteer;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Observers\SiteCacheObserver;
use App\Observers\SitemapObserver;
use App\Shop\RegulatoryScreener;
use App\Support\Anonymiser;
use App\Support\AuditLogger;
use App\Support\ContrastChecker;
use App\Support\DisclosureControl;
use App\Support\Features;
use App\Support\ImageSanitiser;
use App\Support\RetentionRunner;
use App\Support\Settings;
use App\Support\SiteHealth;
use App\Support\TaxDeductibility;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\BackupZipWasCreated;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons: Settings holds the whole table in memory for the request,
        // so a second instance would defeat the point.
        $this->app->singleton(Settings::class);
        $this->app->singleton(ContrastChecker::class);
        $this->app->singleton(TaxDeductibility::class);

        // Privacy: the de-identification boundary and the disclosure control on
        // published statistics. Stateless, but singletons so a future cache of
        // the policy arrays has one place to live.
        $this->app->singleton(Anonymiser::class);
        $this->app->singleton(DisclosureControl::class);

        // Screens shop products against the FDA-regulated keyword list. Called
        // on every product save, so it is worth not rebuilding each time.
        $this->app->singleton(RegulatoryScreener::class);

        // The retention runner holds the registry of models subject to a
        // retention class. Modules register into it as they land, so it needs
        // no knowledge of models that do not exist yet.
        $this->app->singleton(RetentionRunner::class);

        // The only writer to audit_logs — it is the only thing that knows how
        // to extend the hash chain, which is why AuditLog itself is fully
        // guarded.
        $this->app->singleton(AuditLogger::class);

        // Holds the overrides for the request. A singleton so a page rendering
        // twenty flag checks is one query, not twenty — and deliberately not
        // cached beyond the request, so a flag switched during an incident
        // takes effect on the next page load.
        $this->app->singleton(Features::class);

        // Stateless, but a singleton so the GD/EXIF capability checks are not
        // repeated for every file in a bulk upload.
        $this->app->singleton(ImageSanitiser::class);

        /*
         * Media. All four are singletons for the same reason: they each answer
         * a question by asking the SERVER rather than by reading a value, and
         * the answer does not change during a request.
         *
         * ImageToolchain shells out once per optimiser binary; MediaUsage reads
         * the foreign keys out of information_schema. Neither is expensive
         * once and both are silly thirty times, which is what a page of a media
         * grid would otherwise cost.
         */
        $this->app->singleton(ImageToolchain::class);
        $this->app->singleton(UploadPolicy::class);
        $this->app->singleton(MediaUsage::class);
        $this->app->singleton(MediaLibrary::class);
    }

    public function boot(): void
    {
        /*
         * `@clean($html)` — stored rich text, sanitised on the way out. The
         * only sanctioned way to print CMS HTML on the public site.
         */
        Blade::directive('clean', fn (string $expression): string => "<?php echo \App\Support\Html::clean({$expression}); ?>");

        /*
         * Fixture tables for the test suite.
         *
         * Registered as a migration path rather than created inside a test,
         * because DDL inside a test commits the transaction RefreshDatabase
         * wraps it in, which makes Laravel re-run `migrate:fresh` before every
         * subsequent test. Loading them here means `migrate:fresh` builds them
         * with everything else, once.
         *
         * Only in the testing environment, so deployment never sees them.
         */
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(base_path('tests/database/migrations'));
        }

        /*
         * Fail loudly in development, quietly in production.
         *
         * strictMode turns lazy loading, silently-discarded attributes and
         * accessing a missing attribute into exceptions. Those are exactly the
         * bugs that hide until a donation page is under load, so they should be
         * impossible to miss locally — but must not take a live donation form
         * down over a template referencing a stale field.
         */
        Model::shouldBeStrict(! $this->app->isProduction());

        // Money and donation totals are only ever assigned as Money or as
        // integers; unguarded mass assignment on financial models is how a
        // crafted request sets an amount it should not.
        Model::unguard(false);

        // Ghana is UTC+0 with no DST, so this changes no arithmetic — but being
        // explicit means "today" in a report is unambiguous.
        Date::use(Carbon::class);

        $this->registerRetentionSubjects();
        $this->recordBackupOutcomes();
        $this->recordQueueHeartbeat();

        /*
         * Strip camera metadata the moment a file is added.
         *
         * Synchronously, not queued. The queue runs from cron once a minute, so
         * a queued sanitiser leaves a window in which the unsanitised original
         * is on disk and reachable — and for a photograph carrying a child's
         * home coordinates that window is the entire problem.
         */
        Event::listen(MediaHasBeenAddedEvent::class, SanitiseUploadedImage::class);

        // Every model a sitemap lists forgets the cached sitemap on save.
        foreach ([Page::class, Post::class, Project::class, Cause::class, Product::class, \App\Models\Event::class, FocusArea::class, Faq::class, Gallery::class, Document::class] as $listed) {
            $listed::observe(SitemapObserver::class);
        }

        /*
         * Everything the public site shows starts a new cache generation when
         * it changes — the fragment cache and the full-page cache both key on
         * it (App\Support\SiteCache). Over-inclusive on purpose: a bump is one
         * cache write, a stale page is a support ticket.
         */
        foreach ([
            Page::class, PageSection::class, Menu::class, MenuItem::class,
            Announcement::class, ThemeSetting::class, Division::class,
            Post::class, BlogCategory::class, Tag::class,
            Project::class, ProjectUpdate::class, Cause::class, CauseUpdate::class,
            Donation::class, ImpactMetric::class, Story::class,
            Product::class, ProductVariant::class, ProductCategory::class, ProductReview::class,
            \App\Models\Event::class, EventRegistration::class,
            FocusArea::class, Faq::class, FaqCategory::class, Gallery::class, Document::class,
            Testimonial::class, Partner::class, TeamMember::class, TeamDepartment::class,
            Office::class, VolunteerOpportunity::class, Media::class, Redirect::class,
        ] as $shown) {
            $shown::observe(SiteCacheObserver::class);
        }

        // The visit counter writes in terminate(); a singleton is what carries
        // its pending rows from handle() to there.
        $this->app->singleton(CountVisit::class);

        $this->recordAuthenticationEvents();
        $this->registerRateLimiters();
        $this->resolveImageDriver();
        $this->forceHttpsWhereConfigured();
    }

    /**
     * Tell spatie which image driver this particular server actually has.
     *
     * The master prompt asks for image handling that works without ImageMagick
     * and degrades gracefully. Degrading gracefully requires knowing, and on
     * shared hosting nobody does: the account is provisioned by somebody else
     * and the PHP build changes when the host upgrades a server.
     *
     * A config value saying "we have Imagick" is a belief; ImageToolchain asks.
     * Setting IMAGE_DRIVER explicitly overrules the detection, which is worth
     * doing only to reproduce a production problem locally.
     */
    private function resolveImageDriver(): void
    {
        if (config('media.driver', 'auto') !== 'auto') {
            config(['media-library.image_driver' => config('media.driver')]);

            return;
        }

        config(['media-library.image_driver' => $this->app->make(ImageToolchain::class)->preferredDriver()]);
    }

    /**
     * The named limiters the public front door hangs off.
     *
     * config/security.php has carried these numbers since Phase 2 and nothing
     * read them, which meant the registration form had no limit at all and the
     * comment explaining how carefully the limits were chosen was describing
     * something that did not exist.
     *
     * ── The key matters as much as the number ───────────────────────────────
     *
     * Login is limited in LoginRequest rather than here, because it needs two
     * keys at once and needs to fire Illuminate\Auth\Events\Lockout — which the
     * `throttle` middleware does not. See App\Http\Requests\Auth\LoginRequest.
     *
     * Password reset is keyed on the ADDRESS as well as the IP. Keyed on IP
     * alone, one person behind a shared mobile-network NAT — which in Ghana is
     * most people — would lock out everybody else on that gateway. Keyed on the
     * address alone, anybody could stop a named donor resetting their password.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(
            (int) config('security.rate_limits.registration', 3)
        )->by((string) $request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(
            (int) config('security.rate_limits.password_reset', 3)
        )->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        /*
         * Re-sending a verification email. Keyed by account, because the person
         * asking is signed in and there is nothing to enumerate — and because
         * the cost being protected is the host's hourly mail cap, which one
         * impatient donor clicking "send it again" eleven times can spend.
         */
        RateLimiter::for('verification', fn (Request $request) => Limit::perMinutes(
            5,
            (int) config('security.rate_limits.password_reset', 3),
        )->by((string) ($request->user()?->getKey() ?? $request->ip())));
    }

    /**
     * Write what happens at the front door into `login_histories` and the audit
     * trail.
     *
     * Both were built earlier with nothing emitting into them — the table since
     * Module 1, the `auth.*` audit events since Module 8.
     */
    private function recordAuthenticationEvents(): void
    {
        Event::listen(Login::class, [RecordAuthenticationEvent::class, 'handleLogin']);
        // A guest basket survives signing in — see App\Shop\CurrentCart.
        Event::listen(Login::class, AttachGuestCart::class);
        Event::listen(Failed::class, [RecordAuthenticationEvent::class, 'handleFailed']);
        Event::listen(Lockout::class, [RecordAuthenticationEvent::class, 'handleLockout']);
        Event::listen(Logout::class, [RecordAuthenticationEvent::class, 'handleLogout']);
        Event::listen(PasswordReset::class, [RecordAuthenticationEvent::class, 'handlePasswordReset']);

        // The right password followed by the wrong second factor. Laravel has
        // no event for it, and it is the most interesting failure in the table.
        Event::listen(TwoFactorChallengeFailed::class, [RecordAuthenticationEvent::class, 'handleTwoFactorFailed']);
    }

    /**
     * Force https on every generated URL where configured.
     *
     * `FORCE_HTTPS` has been documented since Phase 2 and read by nothing.
     *
     * It matters beyond the usual reasons here: this application sets a session
     * cookie authenticating an administrator who can read beneficiary case
     * files, and one plaintext request on a shared network hands that cookie
     * over. Behind a cPanel proxy, Laravel frequently cannot tell it is already
     * on https, and generates http links that a browser then upgrades — or
     * does not.
     */
    private function forceHttpsWhereConfigured(): void
    {
        if (config('security.force_https', false)) {
            URL::forceScheme('https');
        }
    }

    /**
     * Write what spatie/laravel-backup did into a table somebody can read.
     *
     * The package emails on failure, which is right and is not enough: an email
     * is read once by whoever happened to open it, and a backup that has been
     * failing for three weeks looks exactly like one nobody is emailing about.
     */
    /**
     * The queue worker's pulse.
     *
     * `queue:work --stop-when-empty` fires `Looping` once per pass, empty
     * queue included, so a cron line that is running leaves a timestamp
     * every minute even when there is nothing to do. Site Health reads it:
     * an empty queue and a fresh pulse is a working system; an empty queue
     * and no pulse is a missing cron line, which used to look identical.
     */
    private function recordQueueHeartbeat(): void
    {
        Queue::looping(function (): void {
            try {
                Cache::put(SiteHealth::QUEUE_HEARTBEAT_KEY, now()->toIso8601String(), now()->addHours(6));
            } catch (Throwable) {
                // A cache that cannot be written is Site Health's own problem to report.
            }
        });
    }

    private function recordBackupOutcomes(): void
    {
        Event::listen(BackupZipWasCreated::class, [RecordBackupOutcome::class, 'handleZipCreated']);
        Event::listen(BackupWasSuccessful::class, [RecordBackupOutcome::class, 'handleSuccess']);
        Event::listen(BackupHasFailed::class, [RecordBackupOutcome::class, 'handleFailure']);
    }

    /**
     * Tell the retention runner which models fall under which policy.
     *
     * Registered here rather than discovered, so the set of tables subject to
     * automated destruction is a short, readable list somebody can check
     * against the policy — and so a model that should be swept but is missing
     * from it is visible by its absence.
     *
     * Beneficiary reports its class per instance (declined, withdrawn and
     * closed cases have very different lives), so it registers under all three.
     */
    private function registerRetentionSubjects(): void
    {
        $runner = $this->app->make(RetentionRunner::class);

        foreach ([
            'beneficiary_application_declined',
            'beneficiary_application_withdrawn',
            'beneficiary_case_record',
        ] as $class) {
            $runner->register($class, Beneficiary::class);
        }

        $runner->register('beneficiary_sensitive_document', BeneficiaryDocument::class);
        $runner->register('beneficiary_case_record', BeneficiaryDocument::class);

        // Volunteers. An application's class depends on its outcome, the same
        // way a beneficiary's does.
        foreach ([
            'volunteer_application_declined',
            'volunteer_application_withdrawn',
            'volunteer_record',
        ] as $class) {
            $runner->register($class, VolunteerApplication::class);
        }

        $runner->register('volunteer_record', Volunteer::class);

        // Event registrations and prayer requests. Both hold personal data with
        // a short, purpose-limited life — a prayer request especially, which is
        // the most sensitive thing most people ever send this foundation.
        $runner->register('event_registration', EventRegistration::class);
        $runner->register('prayer_request', PrayerRequest::class);

        /*
         * Delivery logs. Two years, then deleted.
         *
         * `suppressions` is deliberately absent from this list and must stay
         * absent: forgetting that somebody objected to being contacted is how
         * they start receiving mail again after asking not to. The list exists
         * precisely to prevent processing, so sweeping it would defeat it.
         */
        $runner->register('communication_log', EmailLog::class);
        $runner->register('communication_log', SmsLog::class);
    }
}
