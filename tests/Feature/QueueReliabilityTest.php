<?php

declare(strict_types=1);

use App\Filament\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Filament\Resources\EmailLogs\Pages\ViewEmailLog;
use App\Filament\Resources\FailedJobs\FailedJobResource;
use App\Filament\Resources\FailedJobs\Pages\ListFailedJobs;
use App\Filament\Resources\ScheduledMessages\Pages\ListScheduledMessages;
use App\Filament\Resources\SmsLogs\Pages\ListSmsLogs;
use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\FailedJob;
use App\Models\ScheduledMessage;
use App\Models\SmsLog;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 10 — reliability without a terminal
|--------------------------------------------------------------------------
|
| THE WORKER HAS A PULSE. An empty queue used to look the same whether the
| cron line existed or not; now the worker leaves a timestamp every pass
| and the health page reads it.
|
| A FAILED JOB CAN BE RETRIED OR LET GO from a screen, because the person
| who will see it has no shell. The outbox and the two logs are screens too,
| behind the permissions that were seeded in Phase 3 with nothing behind them.
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

function opsUser(array $permissions): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('reads the worker pulse: alive, stale, or never seen', function () {
    Cache::forget(SiteHealth::QUEUE_HEARTBEAT_KEY);
    expect(app(SiteHealth::class)->checks()->firstWhere('key', 'queue')->status)->toBe(HealthCheck::UNKNOWN);

    Cache::forever(SiteHealth::QUEUE_HEARTBEAT_KEY, now()->toIso8601String());
    $check = app(SiteHealth::class)->checks()->firstWhere('key', 'queue');
    expect($check->status)->toBe(HealthCheck::OK)->and($check->value)->toContain('Worker alive');

    Cache::forever(SiteHealth::QUEUE_HEARTBEAT_KEY, now()->subHour()->toIso8601String());
    expect(app(SiteHealth::class)->checks()->firstWhere('key', 'queue')->status)->toBe(HealthCheck::WARNING);
});

it('leaves a pulse when the worker runs, even with nothing to do', function () {
    Cache::forget(SiteHealth::QUEUE_HEARTBEAT_KEY);

    // --memory: the worker exits 12 once the PHP process is past its memory
    // ceiling, and late in the full suite the test process already is.
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--max-time' => 5, '--memory' => 2048])->assertSuccessful();

    expect(Cache::get(SiteHealth::QUEUE_HEARTBEAT_KEY))->not->toBeNull();
});

it('lists failed jobs and lets queue.manage retry or discard them, audited', function () {
    $this->actingAs(opsUser(['queue.manage']));

    $payload = json_encode(['uuid' => ($uuid = (string) Str::uuid()), 'displayName' => 'App\\Jobs\\ProcessPaymentWebhook', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => 'App\\Jobs\\ProcessPaymentWebhook', 'command' => 'O:0:""']]);
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $payload,
        'exception' => "RuntimeException: The gateway said no\n#0 somewhere", 'failed_at' => now(),
    ]);
    $job = FailedJob::firstOrFail();

    Livewire::test(ListFailedJobs::class)
        ->assertOk()
        ->assertSee('ProcessPaymentWebhook')
        ->assertSee('The gateway said no')
        ->callAction(TestAction::make('discard')->table($job));

    expect(FailedJob::count())->toBe(0)
        ->and(AuditLog::where('event', 'queue.job_discarded')->exists())->toBeTrue();
});

it('hides the failed-jobs screen from somebody without queue.manage', function () {
    $this->actingAs(opsUser(['orders.view']));

    expect(FailedJobResource::canViewAny())->toBeFalse();
});

it('shows the outbox with the hourly allowance and lets messages.cancel stop a waiting message', function () {
    $this->actingAs(opsUser(['messages.view', 'messages.cancel']));

    $waiting = ScheduledMessage::create([
        'channel' => 'email', 'template_key' => 'donation.receipt', 'category' => 'transactional',
        'to_address' => 'ama@example.test', 'payload' => [], 'send_after' => now()->addMinute(),
    ]);

    Livewire::test(ListScheduledMessages::class)
        ->assertOk()
        ->assertSee('ama@example.test')
        ->assertSee('per hour')
        ->callAction(TestAction::make('cancel')->table($waiting), ['reason' => 'Sent by hand instead.']);

    expect($waiting->fresh()->status)->toBe(ScheduledMessage::STATUS_CANCELLED);
});

it('shows the email and SMS logs to the log permissions, with the body only where it was stored', function () {
    $this->actingAs(opsUser(['logs.email.view', 'logs.sms.view']));

    $stored = EmailLog::factory()->create(['to_address' => 'ama@example.test', 'subject' => 'Your receipt', 'body_html' => '<p>Thank you Ama</p>', 'body_stored' => true]);
    $bulk = EmailLog::factory()->create(['to_address' => 'kofi@example.test', 'subject' => 'Newsletter', 'body_html' => null, 'body_stored' => false]);
    SmsLog::factory()->create(['to_number' => '+233241234567', 'body' => 'Your order is on its way.', 'status' => SmsLog::STATUS_SENT, 'sent_at' => now(), 'estimated_cost_minor' => 8]);

    Livewire::test(ListEmailLogs::class)->assertOk()->assertSee('Your receipt');
    Livewire::test(ViewEmailLog::class, ['record' => $stored->getRouteKey()])->assertOk()->assertSee('Thank you Ama', escape: false);
    Livewire::test(ViewEmailLog::class, ['record' => $bulk->getRouteKey()])->assertOk()->assertSee('was not stored');
    Livewire::test(ListSmsLogs::class)->assertOk()->assertSee('+233241234567')->assertSee('GH₵ 0.08 this month');
});

it('says where receipts leave from, and objects to the log mailer or the shared box on production', function () {
    $health = fn () => app(SiteHealth::class)->checks()->firstWhere('key', 'mail');

    config()->set('mail.default', 'log');
    expect($health()->status)->toBe(HealthCheck::OK);

    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', '');
    expect($health()->status)->toBe(HealthCheck::CRITICAL)->and($health()->value)->toContain('no key');

    config()->set('services.resend.key', 're_test_123');
    expect($health()->status)->toBe(HealthCheck::OK)->and($health()->value)->toBe('Resend');

    app()->detectEnvironment(fn () => 'production');
    config()->set('mail.default', 'log');
    expect($health()->status)->toBe(HealthCheck::CRITICAL);

    config()->set('mail.default', 'smtp');
    config()->set('app.url', 'https://greaterhopefoundations.com');
    config()->set('mail.mailers.smtp.host', 'mail.greaterhopefoundations.com');
    expect($health()->status)->toBe(HealthCheck::WARNING)->and($health()->advice)->toContain('shared hosting IP');

    config()->set('mail.mailers.smtp.host', 'smtp-relay.brevo.com');
    expect($health()->status)->toBe(HealthCheck::OK)->and($health()->value)->toContain('relay');

    app()->detectEnvironment(fn () => 'testing');
});
