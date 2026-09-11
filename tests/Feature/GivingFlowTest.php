<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Donor;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\ScheduledMessage;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\PaymentManager;
use App\Payments\RecurringGivingService;
use App\Payments\RefundService;
use App\Support\Settings;
use App\Support\ThemeTokens;
use App\ValueObjects\Money;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 8 — the giving flow
|--------------------------------------------------------------------------
|
| MONEY MUST NEVER BE LOST, DOUBLE-COUNTED, OR CREDITED WITHOUT PROOF. Every
| test here is about one of those three.
|
| A direct mobile-money charge leaves the donor on our page approving a prompt
| on their phone. The waiting page verifies with the gateway on every load; a
| settlement reached that way goes through exactly the path the webhook uses.
| A refund is requested by one person and approved by another, and the ledger
| moves only when the gateway confirms.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);
    $this->seed(MessageTemplateSeeder::class);

    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** @param array<string, mixed> $overrides */
function giftPayload(array $overrides = []): array
{
    return array_merge([
        'amount' => '50.00',
        'donor_name' => 'Ama Mensah',
        'donor_email' => 'ama@example.test',
        'donor_phone' => '0241234567',
        'consent' => '1',
    ], $overrides);
}

// ── The form ────────────────────────────────────────────────────────────────

it('normalises the phone number to +233 and keeps the attribution', function () {
    $this->post(route('donate.store'), giftPayload([
        'source' => 'radio',
        'utm_source' => 'whatsapp',
        'utm_campaign' => 'harvest-2026',
        'public_message' => 'For Auntie Cecilia.',
        'donor_address' => '12 Ring Road',
        'donor_city' => 'Bolgatanga',
    ]));

    $donation = Donation::first();

    expect($donation->donor_phone)->toBe('+233241234567')
        ->and($donation->source)->toBe('radio')
        ->and($donation->utm)->toBe(['source' => 'whatsapp', 'campaign' => 'harvest-2026'])
        ->and($donation->public_message)->toBe('For Auntie Cecilia.')
        ->and($donation->donor?->address)->toBe('12 Ring Road')
        ->and($donation->transaction->request_payload['donation_id'])->toBe($donation->id)
        ->and($donation->transaction->request_payload['source'])->toBe('radio');
});

it('carries the attribution from the link into the form as hidden fields', function () {
    $this->get(route('donate', ['utm_source' => 'whatsapp', 'source' => 'radio']))
        ->assertOk()
        ->assertSee('name="utm_source" value="whatsapp"', escape: false)
        ->assertSee('name="source" value="radio"', escape: false);
});

it('records the frequency the donor chose, and establishes it only once the money is in', function () {
    $this->post(route('donate.store'), giftPayload(['frequency' => 'quarterly']));

    $donation = Donation::first();

    expect($donation->wants_recurring)->toBeTrue()
        ->and($donation->recurring_interval)->toBe('quarterly')
        ->and(Subscription::count())->toBe(0);

    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    $subscription = Subscription::first();

    expect($subscription->interval)->toBe('quarterly')
        ->and($subscription->next_charge_on->toDateString())->toBe(now()->addMonthsNoOverflow(3)->toDateString())
        ->and(ScheduledMessage::where('template_key', 'recurring.established')->first()?->payload['manage_url'])->toContain('/giving/'.$subscription->ulid);
});

it('offers weekly giving and advances a weekly gift by seven days', function () {
    $this->post(route('donate.store'), giftPayload(['frequency' => 'weekly']));
    app(FakeGateway::class)->deliverWebhook(Donation::first()->transaction);

    expect(Subscription::first()->next_charge_on->toDateString())->toBe(now()->addWeek()->toDateString());
});

it('refuses a frequency it does not offer', function () {
    $this->post(route('donate.store'), giftPayload(['frequency' => 'daily']))->assertSessionHasErrors('frequency');
});

// ── Direct mobile money ─────────────────────────────────────────────────────

it('charges a wallet directly and leaves the donor on a page that says what to do', function () {
    $this->post(route('donate.store'), giftPayload(['pay_with' => 'momo', 'momo_provider' => 'mtn', 'momo_phone' => '0241234567']))
        ->assertRedirect(route('donate.thanks', Donation::first()));

    $donation = Donation::first();
    $transaction = $donation->transaction;

    expect($transaction->channel)->toBe('mobile_money')
        ->and($transaction->momo_network)->toBe('mtn')
        ->and($transaction->status)->toBe(PaymentStatus::Pending)
        ->and($transaction->awaiting_action)->toBe('pay_offline')
        ->and($transaction->authorization_url)->toBeNull()
        ->and($donation->status)->toBe(DonationStatus::Pending);

    $this->get(route('donate.thanks', $donation))
        ->assertOk()
        ->assertSee('Approve the payment on your phone')
        ->assertSee($transaction->display_text)
        ->assertSee(route('donate.status', $donation), escape: false);
});

it('needs the network and the wallet number for a direct charge', function () {
    $this->post(route('donate.store'), giftPayload(['pay_with' => 'momo']))
        ->assertSessionHasErrors(['momo_provider', 'momo_phone']);

    expect(Donation::count())->toBe(0);
});

it('asks for the code when the network wants one, and refuses a wrong code without charging', function () {
    $this->post(route('donate.store'), giftPayload(['pay_with' => 'momo', 'momo_provider' => 'vod', 'momo_phone' => '0201234500']));

    $donation = Donation::first();

    expect($donation->transaction->awaiting_action)->toBe('send_otp');

    $this->get(route('donate.thanks', $donation))->assertOk()->assertSee('Enter the code');

    $this->post(route('donate.otp', $donation), ['otp' => '000000'])->assertSessionHasErrors('otp');
    expect($donation->fresh()->status)->toBe(DonationStatus::Failed);
});

it('accepts the code and moves on to the handset prompt', function () {
    $this->post(route('donate.store'), giftPayload(['pay_with' => 'momo', 'momo_provider' => 'vod', 'momo_phone' => '0201234500']));
    $donation = Donation::first();

    $this->post(route('donate.otp', $donation), ['otp' => '123456'])->assertRedirect(route('donate.thanks', $donation));

    expect($donation->transaction->fresh()->awaiting_action)->toBe('pay_offline');
});

it('marks a declined wallet as failed and tells the donor nothing was taken', function () {
    $this->post(route('donate.store'), giftPayload(['pay_with' => 'momo', 'momo_provider' => 'atl', 'momo_phone' => '0271234599']));

    $donation = Donation::first();

    expect($donation->status)->toBe(DonationStatus::Failed)
        ->and(ScheduledMessage::where('template_key', 'donation.failed')->first()?->payload['retry_url'])->toContain('amount=50.00');

    $this->get(route('donate.thanks', $donation))->assertOk()->assertSee('did not go through');
});

it('settles the direct charge through the webhook, exactly like any other', function () {
    $this->post(route('donate.store'), giftPayload(['pay_with' => 'momo', 'momo_provider' => 'mtn', 'momo_phone' => '0241234567']));
    $donation = Donation::first();

    // The donor approved on the phone; the sandbox delivers the signed webhook.
    $this->post(route('payments.fake.pay', $donation->transaction->gateway_reference), ['outcome' => 'success']);

    expect($donation->fresh()->status)->toBe(DonationStatus::Completed)
        ->and(DonationReceipt::count())->toBe(1);

    $this->getJson(route('donate.status', $donation))
        ->assertOk()
        ->assertJson(['status' => 'completed', 'settled' => true]);
});

// ── Verification, not trust ─────────────────────────────────────────────────

it('verifies with the gateway on the callback rather than waiting for the queue', function () {
    $this->post(route('donate.store'), giftPayload());
    $donation = Donation::first();

    // No webhook has arrived. The fake gateway's verify answers "paid".
    expect($donation->status)->toBe(DonationStatus::Pending);

    $this->get(route('donate.callback', ['reference' => $donation->transaction->gateway_reference]))
        ->assertRedirect(route('donate.thanks', $donation));

    expect($donation->fresh()->status)->toBe(DonationStatus::Completed);
});

it('does not settle a gift the gateway still calls pending, however the callback is dressed up', function () {
    $this->post(route('donate.store'), giftPayload());
    $donation = Donation::first();
    $donation->transaction->update(['gateway_reference' => $donation->transaction->gateway_reference.'-PENDING']);

    $this->get(route('donate.callback', ['reference' => $donation->transaction->fresh()->gateway_reference, 'status' => 'success']));

    expect($donation->fresh()->status)->toBe(DonationStatus::Pending);
});

it('counts a replayed webhook once', function () {
    $this->post(route('donate.store'), giftPayload());
    $donation = Donation::first();

    app(FakeGateway::class)->deliverWebhook($donation->transaction);
    app(FakeGateway::class)->deliverWebhook($donation->transaction);
    app(PaymentManager::class)->verifyAndSettle($donation->transaction->fresh());

    expect($donation->fresh()->status)->toBe(DonationStatus::Completed)
        ->and($donation->cause->fresh()->raisedAmount()->toMinor())->toBe(5000)
        ->and($donation->fresh()->donor->donation_count)->toBe(1)
        ->and(DonationReceipt::count())->toBe(1)
        ->and(ScheduledMessage::where('template_key', 'donation.receipt')->count())->toBe(1);
});

it('treats a webhook from an address outside the allowlist as unverified', function () {
    config(['payments.paystack.webhook_ips' => ['52.31.139.75']]);

    $this->post(route('donate.store'), giftPayload());
    $donation = Donation::first();

    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 99, 'reference' => $donation->transaction->gateway_reference, 'status' => 'success', 'amount' => 5000, 'currency' => 'GHS']]);
    $signature = hash_hmac('sha512', $body, 'sk_test_webhook_secret_for_tests');

    $this->call('POST', config('payments.paystack.webhook_path'), [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'REMOTE_ADDR' => '203.0.113.9', 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk();

    expect(PaymentWebhookEvent::first()->signature_valid)->toBeFalse()
        ->and($donation->fresh()->status)->toBe(DonationStatus::Pending);
});

// ── The receipt ─────────────────────────────────────────────────────────────

it('issues a receipt with a signed download link and renders it as a PDF', function () {
    $this->post(route('donate.store'), giftPayload());
    $donation = Donation::first();
    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    $receipt = DonationReceipt::first();
    $email = ScheduledMessage::where('template_key', 'donation.receipt')->first();

    expect($email->payload['receipt_url'])->toContain('/receipts/'.$receipt->ulid.'/download');

    $this->get($email->payload['receipt_url'])
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // The same link without its signature is nothing.
    $this->get(route('receipts.download', $receipt))->assertForbidden();

    $this->get(route('donate.thanks', $donation))->assertOk()->assertSee('Download your receipt');
});

it('lets a signed-in donor fetch their own receipt and nobody else\'s', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('donate.store'), giftPayload(['donor_email' => $user->email]));
    $donation = Donation::first();
    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    $receipt = DonationReceipt::first();

    $this->actingAs($user)->get(route('receipts.download', $receipt))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('receipts.download', $receipt))->assertForbidden();
});

// ── Managing a regular gift ─────────────────────────────────────────────────

function establishedGift(): Subscription
{
    test()->post(route('donate.store'), giftPayload(['frequency' => 'monthly']));
    $donation = Donation::first();
    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    return Subscription::first();
}

it('lets a donor pause, change and stop a gift from the signed link, with no account', function () {
    $subscription = establishedGift();
    $manage = ScheduledMessage::where('template_key', 'recurring.established')->first()->payload['manage_url'];

    $this->get($manage)->assertOk()->assertSee('GH₵ 50.00 every month')->assertSee('Pause');
    $this->get(route('giving.manage', $subscription))->assertForbidden();

    $this->post(URL::temporarySignedRoute('giving.amount', now()->addDay(), ['subscription' => $subscription->ulid]), ['amount' => '30.00'])
        ->assertRedirect();
    expect($subscription->fresh()->amount->toMinor())->toBe(3000);

    $this->post(URL::temporarySignedRoute('giving.pause', now()->addDay(), ['subscription' => $subscription->ulid]))->assertRedirect();
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Paused);

    $this->post(URL::temporarySignedRoute('giving.resume', now()->addDay(), ['subscription' => $subscription->ulid]))->assertRedirect();
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

    $this->post(URL::temporarySignedRoute('giving.cancel', now()->addDay(), ['subscription' => $subscription->ulid]), ['reason' => 'Moving abroad.'])->assertRedirect();
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->fresh()->cancel_reason)->toBe('Moving abroad.');

    // An unsigned POST changes nothing.
    $this->post(route('giving.resume', $subscription))->assertForbidden();
});

it('shows a signed-in donor their gifts in the account area', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('donate.store'), giftPayload(['donor_email' => $user->email, 'frequency' => 'monthly']));
    app(FakeGateway::class)->deliverWebhook(Donation::first()->transaction);

    $subscription = Subscription::first();
    Donor::where('email', $user->email)->update(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('account.giving'))->assertOk()->assertSee($subscription->reference);
    $this->actingAs($user)->post(route('giving.pause', $subscription))->assertRedirect(route('account.giving'));

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Paused);
});

it('tells the donor when a charge fails, and pauses with a message after the last attempt', function () {
    config(['payments.recurring.max_failures' => 2]);
    $subscription = establishedGift();
    $subscription->forceFill(['authorization_code' => 'AUTH_DECLINE', 'next_charge_on' => now()->toDateString()])->save();

    app(RecurringGivingService::class)->chargeOne($subscription->fresh());

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Failing)
        ->and(ScheduledMessage::where('template_key', 'recurring.failed')->where('channel', 'email')->count())->toBe(1)
        ->and(ScheduledMessage::where('template_key', 'recurring.failed')->where('channel', 'sms')->count())->toBe(1);

    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();
    app(RecurringGivingService::class)->chargeOne($subscription->fresh());

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Paused)
        ->and(ScheduledMessage::where('template_key', 'recurring.paused')->count())->toBe(1);
});

// ── Refunds ─────────────────────────────────────────────────────────────────

it('refunds with two people and moves the ledger only when the gateway confirms', function () {
    $this->post(route('donate.store'), giftPayload());
    $donation = Donation::first();
    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    $requester = User::factory()->staff()->create();
    $approver = User::factory()->staff()->create();
    $service = app(RefundService::class);

    $refund = $service->request($donation->transaction->fresh(), Money::ofMinor(5000), 'Duplicate gift.', $requester);

    expect($refund->status)->toBe(Refund::STATUS_REQUESTED)
        ->and($donation->fresh()->status)->toBe(DonationStatus::Completed);

    expect(fn () => $service->approveAndExecute($refund, $requester))->toThrow(RuntimeException::class);

    $service->approveAndExecute($refund->fresh(), $approver);

    expect($refund->fresh()->status)->toBe(Refund::STATUS_PROCESSED)
        ->and($donation->fresh()->status)->toBe(DonationStatus::Refunded)
        ->and($donation->cause->fresh()->raisedAmount()->toMinor())->toBe(0)
        ->and($donation->fresh()->donor->donation_count)->toBe(0);
});

it('refuses a refund larger than what can still be returned', function () {
    $this->post(route('donate.store'), giftPayload());
    $donation = Donation::first();
    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    expect(fn () => app(RefundService::class)->request($donation->transaction->fresh(), Money::ofMinor(9000), 'Too much.', User::factory()->staff()->create()))
        ->toThrow(RuntimeException::class, 'GH₵ 50.00');
});

it('closes a refund from the gateway\'s webhook when the API answer never came', function () {
    $this->post(route('donate.store'), giftPayload());
    $donation = Donation::first();
    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    $refund = Refund::create([
        'payment_transaction_id' => $donation->transaction->id,
        'amount' => 2000, 'currency' => 'GHS',
        'status' => Refund::STATUS_PENDING, 'reason' => 'Partial.',
        'requested_by' => User::factory()->staff()->create()->id,
    ]);

    app(RefundService::class)->applyWebhook('refund.processed', ['id' => 555, 'transaction_reference' => $donation->transaction->gateway_reference]);

    expect($refund->fresh()->status)->toBe(Refund::STATUS_PROCESSED)
        ->and($refund->fresh()->gateway_reference)->toBe('555')
        // Partial: the gift stays completed; the appeal total is recomputed.
        ->and($donation->fresh()->status)->toBe(DonationStatus::Completed);
});
