<?php

declare(strict_types=1);

use App\Communications\ArkeselGateway;
use App\Communications\BroadcastSender;
use App\Communications\Contracts\ReportsBalance;
use App\Communications\Contracts\SmsGateway;
use App\Communications\HubtelGateway;
use App\Communications\LogSmsGateway;
use App\Communications\TwilioGateway;
use App\Filament\Resources\SmsBroadcasts\Pages\CreateSmsBroadcast;
use App\Filament\Resources\SmsBroadcasts\Pages\EditSmsBroadcast;
use App\Filament\Resources\Suppressions\Pages\ListSuppressions;
use App\Models\Donor;
use App\Models\ScheduledMessage;
use App\Models\SmsBroadcast;
use App\Models\SmsLog;
use App\Models\Suppression;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Providers\CommunicationServiceProvider;
use App\Support\HealthCheck;
use App\Support\Settings;
use App\Support\SiteHealth;
use App\Support\ThemeTokens;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 10 — SMS: four gateways, one contract, and a broadcast that is
| costed before it is approved and approved before it is sent
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(MessageTemplateSeeder::class);

    config([
        'communications.sms.arkesel.api_key' => 'ark-key',
        'communications.sms.hubtel.client_id' => 'hub-id',
        'communications.sms.hubtel.client_secret' => 'hub-secret',
        'communications.sms.twilio.account_sid' => 'ACxxx',
        'communications.sms.twilio.auth_token' => 'tok',
        'communications.sms.twilio.from' => '+15005550006',
        'communications.sms.mnotify.api_key' => 'mn-key',
        'communications.sms.cost_per_segment_minor' => 4,
    ]);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function smsLogRow(): SmsLog
{
    return SmsLog::factory()->create(['to_number' => '+233241234567', 'sender_id' => 'GreaterHope', 'body' => 'Hello from the test.']);
}

// ── Gateways ────────────────────────────────────────────────────────────────

it('sends through Arkesel with the key in the header and reads acceptance, not delivery', function () {
    Http::fake([
        'sms.arkesel.com/api/v2/sms/send' => Http::response(['status' => 'success', 'data' => [['recipient' => '233241234567', 'id' => 'ark-1', 'status' => 'Submitted']]]),
        'sms.arkesel.com/api/v2/sms/ark-1' => Http::response(['status' => 'success', 'data' => ['status' => 'DELIVERED']]),
        'sms.arkesel.com/api/v2/clients/balance-details' => Http::response(['status' => 'success', 'data' => ['sms_balance' => 1250]]),
    ]);

    $gateway = new ArkeselGateway;
    $result = $gateway->send(smsLogRow());

    expect($result->successful)->toBeTrue()->and($result->providerMessageId)->toBe('ark-1');
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('api-key', 'ark-key') && $r['sender'] === 'GreaterHope' && $r['recipients'] === ['233241234567']);

    expect($gateway->deliveryReport('ark-1'))->toBe(['status' => 'delivered', 'detail' => 'delivered'])
        ->and($gateway->balance()->format())->toBe('1,250 credits');
});

it('refuses an Arkesel error as a rejection with the provider\'s words', function () {
    Http::fake(['sms.arkesel.com/*' => Http::response(['status' => 'error', 'message' => 'Insufficient balance'], 200)]);

    $result = (new ArkeselGateway)->send(smsLogRow());

    expect($result->successful)->toBeFalse()->and($result->message)->toBe('Insufficient balance');
});

it('sends through Hubtel with basic auth and reports no balance', function () {
    Http::fake([
        'smsc.hubtel.com/v1/messages/send' => Http::response(['Status' => 0, 'MessageId' => 'hub-9']),
        'smsc.hubtel.com/v1/messages/hub-9' => Http::response(['Status' => 'Delivered']),
    ]);

    $gateway = new HubtelGateway;
    $result = $gateway->send(smsLogRow());

    expect($result->successful)->toBeTrue()->and($result->providerMessageId)->toBe('hub-9');
    Http::assertSent(fn (Request $r): bool => str_starts_with((string) $r->header('Authorization')[0], 'Basic ') && $r['From'] === 'GreaterHope' && $r['To'] === '+233241234567');

    expect($gateway->deliveryReport('hub-9')['status'])->toBe('delivered')
        ->and($gateway)->not->toBeInstanceOf(ReportsBalance::class);
});

it('sends through Twilio form-encoded and reads the balance in its currency', function () {
    Http::fake([
        'api.twilio.com/2010-04-01/Accounts/ACxxx/Messages.json' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201),
        'api.twilio.com/2010-04-01/Accounts/ACxxx/Messages/SM1.json' => Http::response(['status' => 'undelivered', 'error_message' => 'Unreachable']),
        'api.twilio.com/2010-04-01/Accounts/ACxxx/Balance.json' => Http::response(['balance' => '12.50', 'currency' => 'USD']),
    ]);

    $gateway = new TwilioGateway;
    $result = $gateway->send(smsLogRow());

    expect($result->successful)->toBeTrue()->and($result->providerMessageId)->toBe('SM1');
    Http::assertSent(fn (Request $r): bool => $r['To'] === '+233241234567' && $r['From'] === '+15005550006' && $r['Body'] === 'Hello from the test.');

    expect($gateway->deliveryReport('SM1'))->toBe(['status' => 'undelivered', 'detail' => 'undelivered: Unreachable'])
        ->and($gateway->balance()->format())->toBe('USD 12.50')
        ->and($gateway->balance()->isLow(50))->toBeTrue();
});

it('chooses the gateway from the setting first and the .env second', function () {
    config(['communications.sms.driver' => 'mnotify']);
    expect(CommunicationServiceProvider::smsDriver())->toBe('mnotify');

    setting()->set('communications.sms_driver', 'arkesel');
    app(Settings::class)->flush();
    expect(CommunicationServiceProvider::smsDriver())->toBe('arkesel');

    app()->forgetInstance(SmsGateway::class);
    expect(app(SmsGateway::class))->toBeInstanceOf(ArkeselGateway::class);

    setting()->set('communications.sms_driver', 'log');
    app(Settings::class)->flush();
    app()->forgetInstance(SmsGateway::class);
    expect(app(SmsGateway::class))->toBeInstanceOf(LogSmsGateway::class);
});

it('shows the balance in its unit on the health page and emails when it is low, once a day', function () {
    setting()->set('communications.sms_driver', 'mnotify');
    setting()->set('communications.alert_email', 'director@example.test');
    app(Settings::class)->flush();
    app()->forgetInstance(SmsGateway::class);

    Http::fake(['api.mnotify.com/api/balance/sms*' => Http::response(['balance' => 12])]);

    $check = app(SiteHealth::class)->checks()->firstWhere('key', 'sms');
    expect($check->value)->toBe('12 credits')->and($check->status)->toBe(HealthCheck::WARNING);

    $this->artisan('scghf:sms-balance --execute')->assertSuccessful();
    $this->artisan('scghf:sms-balance --execute')->assertSuccessful();

    $alerts = ScheduledMessage::where('template_key', 'sms.low_credit')->get();
    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->to_address)->toBe('director@example.test')
        ->and($alerts->first()->payload['balance'])->toBe('12 credits');
});

// ── Broadcasts ──────────────────────────────────────────────────────────────

it('costs a broadcast, needs a second person, and queues one text per consenting number into the outbox', function () {
    Donor::factory()->count(3)->create(['consent_sms' => true, 'phone' => '+233241234567']);
    Donor::factory()->create(['consent_sms' => true, 'phone' => '+233209876543']);
    Donor::factory()->create(['consent_sms' => false, 'phone' => '+233201111111']);
    Donor::factory()->create(['consent_sms' => true, 'phone' => '+233203333333']);
    Suppression::record(Suppression::CHANNEL_SMS, '+233203333333', Suppression::REASON_UNSUBSCRIBE, 'STOP');

    $drafter = User::factory()->staff()->withTwoFactor()->create();
    $drafter->givePermissionTo(['newsletter.view', 'newsletter.draft']);
    $this->actingAs($drafter);

    Livewire::test(CreateSmsBroadcast::class)
        ->fillForm(['title' => 'Harvest thanks', 'body' => 'Thank you for standing with us this harvest. GreaterHope', 'audience' => SmsBroadcast::AUDIENCE_DONORS])
        ->call('create')
        ->assertHasNoFormErrors();

    $broadcast = SmsBroadcast::firstOrFail();

    // Three donors share a number; one refused SMS; one is on the STOP list.
    expect($broadcast->recipient_count)->toBe(2)
        ->and($broadcast->segments)->toBe(1)
        ->and($broadcast->estimated_cost_minor)->toBe(8)
        ->and($broadcast->created_by)->toBe($drafter->id);

    $page = Livewire::test(EditSmsBroadcast::class, ['record' => $broadcast->getRouteKey()]);
    $page->assertActionHidden('approve')->assertActionHidden('send');

    // The drafter, even with the permission, may not approve their own text.
    $drafter->givePermissionTo('newsletter.send');
    BasePolicy::forgetKnownPermissions();
    Livewire::test(EditSmsBroadcast::class, ['record' => $broadcast->getRouteKey()])
        ->callAction('approve')
        ->assertNotified();
    expect($broadcast->fresh()->isApproved())->toBeFalse();

    $approver = User::factory()->staff()->withTwoFactor()->create();
    $approver->givePermissionTo(['newsletter.view', 'newsletter.draft', 'newsletter.send']);
    $this->actingAs($approver);

    Livewire::test(EditSmsBroadcast::class, ['record' => $broadcast->getRouteKey()])
        ->callAction('approve')
        ->assertNotified('Approved.')
        ->callAction('send')
        ->assertNotified('2 texts queued.');

    $queued = ScheduledMessage::where('template_key', 'sms.broadcast')->get();

    expect($queued)->toHaveCount(2)
        ->and($queued->pluck('to_address')->sort()->values()->all())->toBe(['+233209876543', '+233241234567'])
        ->and($queued->first()->payload['message'])->toContain('harvest')
        ->and($broadcast->fresh()->status)->toBe(SmsBroadcast::STATUS_QUEUED);

    // Queueing again cannot text anybody twice.
    expect(fn () => app(BroadcastSender::class)->queue($broadcast->fresh()))->toThrow(RuntimeException::class);
});

it('refuses a broadcast over the segment budget and takes pasted numbers once each', function () {
    $broadcast = new SmsBroadcast(['title' => 't', 'body' => str_repeat('A long message. ', 25), 'audience' => SmsBroadcast::AUDIENCE_CUSTOM, 'custom_numbers' => "0241234567\n+233 24 123 4567, 020 987 6543"]);

    expect(fn () => $broadcast->save())->toThrow(RuntimeException::class, 'segments');

    $broadcast->body = 'Short.';
    $broadcast->save();

    expect($broadcast->recipient_count)->toBe(2);
});

// ── The do-not-contact list ─────────────────────────────────────────────────

it('lists suppressions, adds one by hand, and releases only with the permission and a reason', function () {
    Suppression::record(Suppression::CHANNEL_EMAIL, 'bounced@example.test', Suppression::REASON_HARD_BOUNCE, 'Mailbox does not exist');
    Suppression::record(Suppression::CHANNEL_EMAIL, 'gone@example.test', Suppression::REASON_ERASURE, 'Asked to be forgotten');

    $viewer = User::factory()->staff()->withTwoFactor()->create();
    $viewer->givePermissionTo(['suppressions.view']);
    $this->actingAs($viewer);

    Livewire::test(ListSuppressions::class)
        ->assertOk()
        ->assertSee('bounced@example.test')
        ->callAction(TestAction::make('add')->table(), ['channel' => 'sms', 'address' => '024 555 6666', 'scope' => Suppression::SCOPE_ALL, 'detail' => 'Asked by phone.']);

    expect(Suppression::where('address', '+233245556666')->where('reason', Suppression::REASON_MANUAL)->exists())->toBeTrue();

    $bounced = Suppression::where('address', 'bounced@example.test')->first();
    $erased = Suppression::where('address', 'gone@example.test')->first();

    Livewire::test(ListSuppressions::class)->assertActionHidden(TestAction::make('release')->table($bounced));

    $releaser = User::factory()->staff()->withTwoFactor()->create();
    $releaser->givePermissionTo(['suppressions.view', 'suppressions.release']);
    $this->actingAs($releaser);

    Livewire::test(ListSuppressions::class)
        ->callAction(TestAction::make('release')->table($bounced), ['reason' => 'Donor confirmed the address by phone.'])
        ->assertActionHidden(TestAction::make('release')->table($erased));

    expect($bounced->fresh()->released_at)->not->toBeNull();
});
