<?php

declare(strict_types=1);

use App\Communications\Contracts\WhatsappGateway;
use App\Communications\DeliveryEventProcessor;
use App\Communications\MessageDispatcher;
use App\Enums\DonationStatus;
use App\Filament\Resources\WhatsappTemplates\Pages\ListWhatsappTemplates;
use App\Models\Cause;
use App\Models\CauseUpdate;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\InboundWebhookEvent;
use App\Models\ScheduledMessage;
use App\Models\SmsLog;
use App\Models\Suppression;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Payments\FakeGateway;
use App\Policies\BasePolicy;
use App\Programmes\CauseUpdateNotifier;
use App\Support\Features;
use App\Support\HealthCheck;
use App\Support\LaunchChecks;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Wave 2 — WhatsApp as a fifth channel (roadmap 1.3)
|--------------------------------------------------------------------------
|
| Meta-approved templates mapped to our keys, the opt-in on the donate
| form, the receipt and the appeal update on WhatsApp for those who asked,
| the same suppression and quiet-hours rules as SMS, Meta's status
| webhook onto the delivery log, and a flag that is OFF until Meta has
| approved the business — with a launch-check row that says what is
| missing when it is on.
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();
    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function whatsappOn(bool $approved = true): void
{
    config(['features.whatsapp' => true]);
    app(Features::class)->flush();

    if ($approved) {
        WhatsappTemplate::query()->update(['is_approved' => true]);
    }
}

function metaStatus(string $messageId, string $status, string $recipient = '233241234567', array $errors = []): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '1', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'statuses' => [array_filter(['id' => $messageId, 'status' => $status, 'timestamp' => (string) time(), 'recipient_id' => $recipient, 'errors' => $errors ?: null])],
    ]]]]]];
}

function postMeta(array $payload, ?string $secret = 'meta-app-secret'): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = $secret === null ? [] : ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret)];

    return test()->call('POST', '/webhooks/delivery/meta', [], [], [], $headers + ['CONTENT_TYPE' => 'application/json'], $body);
}

// ── Templates ───────────────────────────────────────────────────────────────

it('seeds the templates unapproved and refuses to send one until Meta has approved it', function () {
    $template = WhatsappTemplate::query()->where('key', 'donation.receipt')->firstOrFail();

    expect($template->is_approved)->toBeFalse()
        ->and($template->meta_name)->toBe('scghf_donation_receipt')
        ->and($template->variables)->toBe(['name', 'amount', 'reference', 'cause', 'receipt_number']);

    expect(fn () => WhatsappTemplate::forKey('donation.receipt'))->toThrow(RuntimeException::class, 'not been approved');

    $template->forceFill(['is_approved' => true])->save();

    expect(WhatsappTemplate::forKey('donation.receipt')->parameters(['name' => 'Ama', 'amount' => 'GHS 50.00', 'reference' => 'R1', 'cause' => "Harvest\nappeal", 'receipt_number' => 'SCGHF-R-2026-000001']))
        ->toBe(['Ama', 'GHS 50.00', 'R1', 'Harvest appeal', 'SCGHF-R-2026-000001']);
});

// ── Sending ─────────────────────────────────────────────────────────────────

it('records and costs a message on the log driver, and blocks it when the flag is off', function () {
    WhatsappTemplate::query()->update(['is_approved' => true]);

    $log = app(MessageDispatcher::class)->sendWhatsappNow('donation.receipt', '0241234567', ['name' => 'Ama', 'amount' => 'GHS 50.00', 'reference' => 'R1', 'cause' => 'Harvest', 'receipt_number' => 'X']);

    expect($log->channel)->toBe(SmsLog::CHANNEL_WHATSAPP)
        ->and($log->status)->toBe(SmsLog::STATUS_DISABLED)
        ->and($log->blocked_reason)->toContain('FEATURE_WHATSAPP');

    whatsappOn();

    $log = app(MessageDispatcher::class)->sendWhatsappNow('donation.receipt', '0241234567', ['name' => 'Ama', 'amount' => 'GHS 50.00', 'reference' => 'R1', 'cause' => 'Harvest', 'receipt_number' => 'X']);

    expect($log->status)->toBe(SmsLog::STATUS_SENT)
        ->and($log->to_number)->toBe('+233241234567')
        ->and($log->driver)->toBe('whatsapp_log')
        ->and($log->body)->toContain('Thank you, Ama.')
        ->and($log->estimated_cost_minor)->toBe(60)
        ->and($log->provider_message_id)->toStartWith('wa_log_');
});

it('honours a WhatsApp-specific suppression, separately from SMS', function () {
    whatsappOn();
    Suppression::record(Suppression::CHANNEL_WHATSAPP, '+233241234567', Suppression::REASON_UNSUBSCRIBE, 'Replied STOP', 'test');

    $wa = app(MessageDispatcher::class)->sendWhatsappNow('cause.update', '+233241234567', ['name' => 'Ama', 'cause' => 'Harvest', 'title' => 'Halfway', 'cause_url' => 'https://example.test']);
    $sms = app(MessageDispatcher::class)->sendSmsNow('donation.received', '+233241234567', ['amount' => '50.00', 'reference' => 'R1']);

    expect($wa->status)->toBe(SmsLog::STATUS_SUPPRESSED)
        ->and($sms->status)->toBe(SmsLog::STATUS_SENT);
});

it('sends through the Cloud API with the parameters in Meta’s order and keeps the message id', function () {
    whatsappOn();
    config(['communications.whatsapp.driver' => 'cloud', 'communications.whatsapp.access_token' => 'tok', 'communications.whatsapp.phone_number_id' => '1234567890']);
    app()->forgetInstance(WhatsappGateway::class);
    app()->forgetInstance(MessageDispatcher::class);

    Http::fakeSequence('graph.facebook.com/*')
        ->push(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '233241234567']], 'messages' => [['id' => 'wamid.ABC']]], 200)
        ->push(['error' => ['message' => 'Template name does not exist', 'code' => 132001]], 400);

    $log = app(MessageDispatcher::class)->sendWhatsappNow('donation.receipt', '0241234567', ['name' => 'Ama', 'amount' => 'GHS 50.00', 'reference' => 'R1', 'cause' => 'Harvest', 'receipt_number' => 'X']);

    expect($log->status)->toBe(SmsLog::STATUS_SENT)->and($log->provider_message_id)->toBe('wamid.ABC')->and($log->driver)->toBe('whatsapp_cloud');

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return str_contains($request->url(), '/v21.0/1234567890/messages')
            && $request->hasHeader('Authorization', 'Bearer tok')
            && $body['to'] === '233241234567'
            && $body['template']['name'] === 'scghf_donation_receipt'
            && $body['template']['language']['code'] === 'en'
            && array_column($body['template']['components'][0]['parameters'], 'text') === ['Ama', 'GHS 50.00', 'R1', 'Harvest', 'X'];
    });

    // A refusal from Meta is a failed row with Meta's words, not an exception.
    $log = app(MessageDispatcher::class)->sendWhatsappNow('donation.receipt', '0241234567', ['name' => 'Ama', 'amount' => 'GHS 50.00', 'reference' => 'R2', 'cause' => 'Harvest', 'receipt_number' => 'X']);
    expect($log->status)->toBe(SmsLog::STATUS_FAILED)->and($log->error)->toContain('Template name does not exist');
});

// ── The opt-in and the receipt ──────────────────────────────────────────────

it('queues a WhatsApp receipt only for a donor who ticked the box while the channel is on', function () {
    whatsappOn();

    $this->post(route('donate.store'), ['amount' => '50.00', 'donor_name' => 'Ama Mensah', 'donor_email' => 'ama@example.test', 'donor_phone' => '0241234567', 'consent' => '1', 'consent_whatsapp' => '1']);
    $donation = Donation::query()->firstOrFail();
    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    expect($donation->fresh()->consent_whatsapp)->toBeTrue()
        ->and(Donor::query()->firstOrFail()->consent_whatsapp)->toBeTrue()
        ->and(ScheduledMessage::query()->where('channel', 'whatsapp')->where('template_key', 'donation.receipt')->count())->toBe(1)
        ->and(ScheduledMessage::query()->where('channel', 'sms')->count())->toBe(1);

    // No tick: nothing on WhatsApp, the SMS as always.
    $this->post(route('donate.store'), ['amount' => '20.00', 'donor_name' => 'Kofi', 'donor_email' => 'kofi@example.test', 'donor_phone' => '0241234568', 'consent' => '1']);
    app(FakeGateway::class)->deliverWebhook(Donation::query()->latest('id')->firstOrFail()->transaction);

    expect(ScheduledMessage::query()->where('channel', 'whatsapp')->count())->toBe(1);
});

it('ignores the tick and hides the box while the channel is off', function () {
    $this->get(route('donate'))->assertOk()->assertDontSee('consent_whatsapp');

    $this->post(route('donate.store'), ['amount' => '50.00', 'donor_name' => 'Ama', 'donor_email' => 'ama@example.test', 'donor_phone' => '0241234567', 'consent' => '1', 'consent_whatsapp' => '1']);

    expect(Donation::query()->firstOrFail()->consent_whatsapp)->toBeFalse();

    whatsappOn();
    $this->get(route('donate'))->assertOk()->assertSee('consent_whatsapp');
});

it('sends an appeal update on WhatsApp to the donors who asked, and not to the anonymous', function () {
    whatsappOn();
    $cause = Cause::query()->where('is_general_fund', false)->firstOrFail();

    Donation::factory()->create(['cause_id' => $cause->id, 'status' => DonationStatus::Completed->value, 'donor_phone' => '+233241000001', 'donor_name' => 'Ama', 'consent_whatsapp' => true, 'is_anonymous' => false, 'donor_email' => null]);
    Donation::factory()->create(['cause_id' => $cause->id, 'status' => DonationStatus::Completed->value, 'donor_phone' => '+233241000002', 'donor_name' => 'Kofi', 'consent_whatsapp' => true, 'is_anonymous' => true, 'donor_email' => null]);
    Donation::factory()->create(['cause_id' => $cause->id, 'status' => DonationStatus::Completed->value, 'donor_phone' => '+233241000003', 'donor_name' => 'Esi', 'consent_whatsapp' => false, 'donor_email' => null]);

    $update = CauseUpdate::create(['cause_id' => $cause->id, 'title' => 'Halfway there', 'body' => '<p>x</p>', 'is_published' => true, 'published_at' => now()]);

    app(CauseUpdateNotifier::class)->notify($update->fresh()->load('cause'));

    $queued = ScheduledMessage::query()->where('channel', 'whatsapp')->where('template_key', 'cause.update')->get();

    expect($queued)->toHaveCount(1)->and($queued->first()->to_address)->toBe('+233241000001');
});

// ── Meta's webhook ──────────────────────────────────────────────────────────

it('answers Meta’s subscription handshake only with the right token', function () {
    config(['communications.whatsapp.verify_token' => 'hush']);

    $this->get('/webhooks/delivery/meta?hub_mode=subscribe&hub_verify_token=hush&hub_challenge=12345')->assertOk()->assertSee('12345');
    $this->get('/webhooks/delivery/meta?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')->assertForbidden();
    $this->get('/webhooks/delivery/mnotify?hub_mode=subscribe&hub_verify_token=hush&hub_challenge=1')->assertForbidden();
});

it('marks the log delivered or failed from a signed status, and ignores an unsigned one', function () {
    whatsappOn();
    config(['communications.webhooks.providers.meta.secret' => 'meta-app-secret']);

    $log = app(MessageDispatcher::class)->sendWhatsappNow('donation.receipt', '0241234567', ['name' => 'Ama', 'amount' => 'GHS 50.00', 'reference' => 'R1', 'cause' => 'Harvest', 'receipt_number' => 'X']);
    $log->forceFill(['provider_message_id' => 'wamid.ABC'])->save();

    postMeta(metaStatus('wamid.ABC', 'delivered'))->assertOk();
    InboundWebhookEvent::all()->each(fn ($e) => app(DeliveryEventProcessor::class)->process($e));

    expect($log->fresh()->status)->toBe(SmsLog::STATUS_DELIVERED);

    // "read" is a second event (different id), delivered already: no change, no error.
    postMeta(metaStatus('wamid.ABC', 'read'))->assertOk();
    InboundWebhookEvent::all()->each(fn ($e) => app(DeliveryEventProcessor::class)->process($e));
    expect($log->fresh()->status)->toBe(SmsLog::STATUS_DELIVERED);

    $failed = app(MessageDispatcher::class)->sendWhatsappNow('donation.receipt', '0241234568', ['name' => 'Kofi', 'amount' => 'GHS 20.00', 'reference' => 'R2', 'cause' => 'Harvest', 'receipt_number' => 'Y']);
    $failed->forceFill(['provider_message_id' => 'wamid.DEF'])->save();

    postMeta(metaStatus('wamid.DEF', 'failed', '233241234568', [['code' => 131026, 'title' => 'Message undeliverable']]))->assertOk();
    InboundWebhookEvent::all()->each(fn ($e) => app(DeliveryEventProcessor::class)->process($e));

    expect($failed->fresh()->status)->toBe(SmsLog::STATUS_UNDELIVERED)->and($failed->fresh()->error)->toBe('Message undeliverable');

    // Unsigned: stored, never acted on.
    $another = app(MessageDispatcher::class)->sendWhatsappNow('donation.receipt', '0241234569', ['name' => 'Esi', 'amount' => 'GHS 10.00', 'reference' => 'R3', 'cause' => 'Harvest', 'receipt_number' => 'Z']);
    $another->forceFill(['provider_message_id' => 'wamid.GHI'])->save();
    postMeta(metaStatus('wamid.GHI', 'delivered'), secret: null)->assertOk();
    InboundWebhookEvent::all()->each(fn ($e) => app(DeliveryEventProcessor::class)->process($e));
    expect($another->fresh()->status)->toBe(SmsLog::STATUS_SENT);
});

// ── The launch check and the panel ──────────────────────────────────────────

it('passes the launch check with the flag off and names what is missing with it on', function () {
    $row = fn () => app(LaunchChecks::class)->checks()->firstWhere('key', 'whatsapp');

    expect($row()->status)->toBe(HealthCheck::OK);

    config(['features.whatsapp' => true]);
    app(Features::class)->flush();

    $on = $row();
    expect($on->status)->toBe(HealthCheck::CRITICAL)
        ->and($on->advice)->toContain('WHATSAPP_DRIVER=cloud')->toContain('WHATSAPP_ACCESS_TOKEN')->toContain('approved template');
});

it('lets the people who edit texts prepare the templates in the panel', function () {
    $editor = User::factory()->staff()->withTwoFactor()->create();
    $editor->assignRole('Content Editor');
    $this->actingAs($editor->fresh());

    Livewire::test(ListWhatsappTemplates::class)->assertOk()->assertSee('scghf_donation_receipt')->assertSee('Appeal update (WhatsApp)');
});
