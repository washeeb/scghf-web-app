<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Filament\Pages\SiteHealthPage;
use App\Filament\Resources\Faqs\FaqResource;
use App\Filament\Resources\Faqs\Pages\ListFaqs;
use App\Filament\Widgets\GivingOverview;
use App\Filament\Widgets\NeedsAttention;
use App\Models\AuditLog;
use App\Models\BackupLogEntry;
use App\Models\ContactMessage;
use App\Models\Donation;
use App\Models\Faq;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\HealthCheck;
use App\Support\Settings;
use App\Support\SiteHealth;
use App\Support\ThemeTokens;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The admin experience
|--------------------------------------------------------------------------
|
| SITE HEALTH EXISTS BECAUSE EVERYTHING HERE FAILS SILENTLY. A cron line that
| was never added means the queue never drains: receipts sit in a table and no
| error is raised anywhere, because nothing tried and failed — nothing tried at
| all. None of these produce a log line. All of them are one query.
|
| AN EMPTY QUEUE IS NOT A GREEN LIGHT. It is exactly what a working worker and
| a missing cron line both look like, so the check says "cannot tell". A health
| page that reports fine because it could not find a problem is worse than no
| health page.
|
| BACKUPS WERE NEVER RUNNING. spatie/laravel-backup was installed in Phase 2 and
| the listener that records each run was registered in Phase 3 — but there was no
| config/backup.php and no schedule entry, so the listener waited for events
| nobody fired and `backup_log` stayed empty for two phases.
|
| EXPORTING IS AUDITED. `AuditLogger::recordExport()` was written in Phase 3 and
| called from nowhere. An export takes data past the policies and the retention
| sweep onto somebody's laptop; the number of rows is what distinguishes a
| lookup from an incident.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function operator(): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['settings.manage', 'donations.view', 'contact.view', 'faqs.manage']);

    return $user;
}

// ── Site health ─────────────────────────────────────────────────────────────

it('opens the site health page', function () {
    $this->actingAs(operator());

    Livewire::test(SiteHealthPage::class)->assertOk();
});

it('is closed to somebody who could not act on it', function () {
    // The page names the payment mode, the backup state and the hosting
    // configuration. It is not a screen for whoever is answering enquiries.
    $this->actingAs(User::factory()->staff()->withTwoFactor()->create());

    expect(SiteHealthPage::canAccess())->toBeFalse();
});

it('says the scheduler has never run when there is no heartbeat', function () {
    /*
     * ⚠ The check that matters most. When the cron line is missing, everything
     * scheduled stops with no error anywhere: recurring gifts are not charged,
     * the outbox does not drain, receipts are never sent — and the application
     * looks completely healthy from the inside.
     */
    Cache::forget(SiteHealth::HEARTBEAT_KEY);

    $check = app(SiteHealth::class)->checks()->firstWhere('key', 'scheduler');

    expect($check->status)->toBe(HealthCheck::CRITICAL)
        ->and($check->advice)->toContain('schedule:run');
});

it('is satisfied once the scheduler has ticked', function () {
    Cache::forever(SiteHealth::HEARTBEAT_KEY, now()->toIso8601String());

    expect(app(SiteHealth::class)->checks()->firstWhere('key', 'scheduler')->status)
        ->toBe(HealthCheck::OK);
});

it('refuses to call an empty queue healthy', function () {
    /*
     * ⚠ An empty queue is what a working worker and a missing cron line both
     * look like. Reporting green here would be the health page telling somebody
     * their receipts are being sent when nothing is sending them.
     */
    expect(DB::table('jobs')->count())->toBe(0);

    expect(app(SiteHealth::class)->checks()->firstWhere('key', 'queue')->status)
        ->toBe(HealthCheck::UNKNOWN);
});

it('reports a queue nothing is working', function () {
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subHour()->getTimestamp(),
        'created_at' => now()->subHour()->getTimestamp(),
    ]);

    $check = app(SiteHealth::class)->checks()->firstWhere('key', 'queue');

    expect($check->status)->toBe(HealthCheck::CRITICAL)
        ->and($check->advice)->toContain('queue:work');
});

it('says backups have never run when none has', function () {
    // ⚠ True for two phases: the package was installed and the listener
    // registered, and nothing ever fired a backup.
    expect(app(SiteHealth::class)->checks()->firstWhere('key', 'backup')->status)
        ->toBe(HealthCheck::CRITICAL);
});

it('is satisfied by a recent backup', function () {
    BackupLogEntry::factory()->create([
        'type' => BackupLogEntry::TYPE_BACKUP,
        'status' => BackupLogEntry::STATUS_COMPLETED,
    ]);

    expect(app(SiteHealth::class)->checks()->firstWhere('key', 'backup')->status)
        ->toBe(HealthCheck::OK);
});

it('schedules the backup that fills that table', function () {
    /*
     * The listener, the model, the table and the policy all existed. The
     * schedule entry is the piece that was missing, and its absence is what
     * made every one of them decorative.
     */
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($commands->filter(fn (string $c): bool => str_contains($c, 'backup:run')))->not->toBeEmpty()
        ->and($commands->filter(fn (string $c): bool => str_contains($c, 'backup:clean')))->not->toBeEmpty();
});

it('never lets a failing check take down the page it is reported on', function () {
    /*
     * This page is opened when something is already wrong. A check that throws
     * would take down the one screen somebody came to for an explanation, so
     * every check is wrapped and a failure becomes an answer.
     */
    DB::statement('DROP TABLE failed_jobs');

    $check = app(SiteHealth::class)->checks()->firstWhere('key', 'failed_jobs');

    expect($check->status)->toBe(HealthCheck::UNKNOWN)
        ->and(app(SiteHealth::class)->checks())->toHaveCount(13);
});

// ── The preflight command ───────────────────────────────────────────────────

it('reports on the command line what the health page shows', function () {
    // ⚠ `Settings::unfilled()` and `SettingType::validationRule()` both cited
    // this command in their docblocks before it existed — a safety net made of
    // nothing, described in two places as though it were there.
    Cache::forever(SiteHealth::HEARTBEAT_KEY, now()->toIso8601String());
    BackupLogEntry::factory()->create([
        'type' => BackupLogEntry::TYPE_BACKUP,
        'status' => BackupLogEntry::STATUS_COMPLETED,
    ]);

    $this->artisan('scghf:preflight')
        ->expectsOutputToContain('Preflight')
        ->assertExitCode(0);
});

it('fails the deploy when something is actually broken', function () {
    // Exits non-zero so a release script can refuse to finish. A screen nobody
    // has opened yet cannot do that.
    Cache::forget(SiteHealth::HEARTBEAT_KEY);

    $this->artisan('scghf:preflight')->assertExitCode(1);
});

it('does not fail a deploy over an unfilled placeholder', function () {
    /*
     * Deliberately. A registration number arriving a week after launch is
     * normal for a Ghanaian non-profit, and a command that fails the deploy
     * over it is one somebody adds `|| true` to — after which it reports
     * nothing at all, including what should have stopped the deploy.
     */
    Cache::forever(SiteHealth::HEARTBEAT_KEY, now()->toIso8601String());
    BackupLogEntry::factory()->create([
        'type' => BackupLogEntry::TYPE_BACKUP,
        'status' => BackupLogEntry::STATUS_COMPLETED,
    ]);

    expect(app(Settings::class)->unfilled())->not->toBeEmpty();

    $this->artisan('scghf:preflight')->assertExitCode(0);
});

// ── The dashboard ───────────────────────────────────────────────────────────

it('shows the giving overview to somebody who may see donations', function () {
    $this->actingAs(operator());

    Livewire::test(GivingOverview::class)->assertOk();
});

it('hides the giving overview from somebody who may not', function () {
    // The dashboard is the first screen every staff account lands on, so a
    // widget that ignores permissions leaks the foundation's income to the
    // volunteer coordinator.
    $this->actingAs(User::factory()->staff()->withTwoFactor()->create());

    expect(GivingOverview::canView())->toBeFalse();
});

it('counts only completed donations', function () {
    /*
     * A pending donation is somebody who opened the Paystack page. Counting
     * those gives the foundation a figure that goes up when nobody pays, which
     * is the one thing a fundraising number must never do.
     */
    Donation::factory()->create([
        'status' => DonationStatus::Completed->value,
        'amount_minor' => 5000,
        'paid_at' => now(),
    ]);

    Donation::factory()->create([
        'status' => DonationStatus::Pending->value,
        'amount_minor' => 999900,
        'paid_at' => now(),
    ]);

    $this->actingAs(operator());

    Livewire::test(GivingOverview::class)
        ->assertOk()
        ->assertSee('50.00')
        ->assertDontSee('9,999.00');
});

it('surfaces the enquiry that has waited longest', function () {
    ContactMessage::create([
        'name' => 'Ama',
        'email' => 'ama@example.test',
        'message' => 'Waiting.',
    ])->forceFill(['created_at' => now()->subWeeks(3)])->save();

    $this->actingAs(operator());

    Livewire::test(NeedsAttention::class)
        ->assertOk()
        ->assertSee('Unanswered enquiries')
        // The wording comes from Carbon; what matters is that the tile reports
        // the WAIT rather than only the count, because the count alone does not
        // say which message has been sitting there since last month.
        ->assertSee('Oldest has waited');
});

// ── Exports ─────────────────────────────────────────────────────────────────

it('exports a table to CSV', function () {
    $this->actingAs(operator());

    Faq::create(['question' => 'Can I give monthly?', 'answer' => 'Yes, by standing order.']);

    Livewire::test(ListFaqs::class)
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoActionErrors();

    expect(true)->toBeTrue();
});

it('records who exported what, and how many rows', function () {
    /*
     * ⚠ `AuditLogger::recordExport()` was written in Phase 3 and called from
     * nowhere. An export leaves the application's protections behind and lands
     * in somebody's Downloads folder — one person exporting twenty rows is
     * doing their job, one exporting four thousand at 11pm is a question, and
     * it can only be asked if the export was recorded.
     */
    $user = operator();
    $this->actingAs($user);

    Faq::create(['question' => 'One', 'answer' => 'Yes.']);
    Faq::create(['question' => 'Two', 'answer' => 'No.']);

    Livewire::test(ListFaqs::class)->callAction(TestAction::make('export')->table());

    $entry = AuditLog::query()->where('event', 'report.generated')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->record_count)->toBe(2)
        ->and($entry->causer_id)->toBe($user->getKey());
});

// ── Global search ───────────────────────────────────────────────────────────

it('searches inside the answer, not only the question', function () {
    /*
     * A search that only matches titles is one people stop using — the thing
     * somebody remembers about a record is rarely its heading.
     */
    expect(FaqResource::getGloballySearchableAttributes())->toContain('answer');
});

it('says which record a search result is', function () {
    // Two records with the same title are ordinary. A result list that cannot
    // tell them apart sends somebody into the wrong one.
    $faq = Faq::create(['question' => 'Can I give monthly?', 'answer' => 'Yes.']);

    expect(FaqResource::getGlobalSearchResultDetails($faq))->toBeArray();
});
