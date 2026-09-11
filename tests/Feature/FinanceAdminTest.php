<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Pages\GivingReportsPage;
use App\Filament\Resources\Donations\DonationResource;
use App\Filament\Resources\Donations\Pages\CreateDonation;
use App\Filament\Resources\Donations\Pages\ListDonations;
use App\Filament\Resources\Donations\Pages\ViewDonation;
use App\Filament\Resources\Donors\Pages\ViewDonor;
use App\Filament\Resources\PaymentWebhookEvents\Pages\ListPaymentWebhookEvents;
use App\Filament\Resources\PaymentWebhookEvents\PaymentWebhookEventResource;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Models\ScheduledMessage;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use App\Payments\DonorMerger;
use App\Payments\FakeGateway;
use App\Payments\GivingReports;
use App\Payments\PaymentMode;
use App\Payments\RefundService;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use App\ValueObjects\Money;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 8 — the finance admin
|--------------------------------------------------------------------------
|
| NOTHING HERE EDITS MONEY. A donation's amount is written once by the
| gateway and never by a form. The screens request, approve, reconcile,
| resend, replay and report — and every one of those is a row in the audit
| trail, because an auditor asking "who refunded this?" is not a hypothetical.
|
| TWO PEOPLE FOR A REFUND. The request and the approval are separate actions
| on separate screens, and the model refuses a self-approval even when both
| permissions are held by one person.
|
| THE BANNER. Test mode is written across every admin page, so nobody reports
| sandbox gifts as income. Live mode says nothing, so the strip means
| something when it appears.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);
    $this->seed(MessageTemplateSeeder::class);

    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** @param list<string> $permissions */
function financeUser(array $permissions = []): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(array_merge([
        'donations.view', 'donations.view_pii', 'donors.view', 'subscriptions.view', 'payments.view_transactions',
    ], $permissions));

    return $user;
}

/** A completed online gift of GH₵ 50, settled through the fake gateway's webhook. */
function settledGift(array $overrides = []): Donation
{
    test()->post(route('donate.store'), array_merge([
        'amount' => '50.00',
        'donor_name' => 'Ama Mensah',
        'donor_email' => 'ama@example.test',
        'donor_phone' => '0241234567',
        'consent' => '1',
    ], $overrides));

    $donation = Donation::latest('id')->first();
    app(FakeGateway::class)->deliverWebhook($donation->transaction);

    return $donation->fresh();
}

// ── Access ──────────────────────────────────────────────────────────────────

it('opens every finance screen to somebody who may see payments', function () {
    $this->actingAs(financeUser());
    settledGift();

    Livewire::test(ListDonations::class)->assertOk();
    Livewire::test(ViewDonation::class, ['record' => Donation::first()->getRouteKey()])->assertOk();
    Livewire::test(ViewDonor::class, ['record' => Donor::first()->getRouteKey()])->assertOk();
    Livewire::test(ListRefunds::class)->assertOk();
    Livewire::test(ListPaymentWebhookEvents::class)->assertOk();
    Livewire::test(GivingReportsPage::class)->assertOk();
});

it('shows the Refunds and Webhook screens to payments.view_transactions, not to payments.view', function () {
    /*
     * ⚠ The base policy looks for `{prefix}.view`. Payments names its read
     * permission `view_transactions`, and without the override in
     * PaymentPolicy nobody but Super Admin could open a refund.
     */
    expect(RefundResource::canViewAny())->toBeFalse();

    $this->actingAs(financeUser());

    expect(RefundResource::canViewAny())->toBeTrue()
        ->and(PaymentWebhookEventResource::canViewAny())->toBeTrue();

    $this->actingAs(User::factory()->staff()->withTwoFactor()->create());

    expect(RefundResource::canViewAny())->toBeFalse();
});

it('records that a donor record was opened', function () {
    $this->actingAs($user = financeUser());
    $donation = settledGift();

    Livewire::test(ViewDonor::class, ['record' => $donation->donor->getRouteKey()])->assertOk();

    expect(AuditLog::where('event', 'donor.pii_viewed')->where('causer_id', $user->getKey())->exists())->toBeTrue();
});

it('records a donation view only when the donor details were actually shown', function () {
    $donation = settledGift();

    $withoutPii = User::factory()->staff()->withTwoFactor()->create();
    $withoutPii->givePermissionTo(['donations.view']);

    $this->actingAs($withoutPii);
    Livewire::test(ViewDonation::class, ['record' => $donation->getRouteKey()])
        ->assertOk()
        ->assertDontSee('ama@example.test')
        ->assertDontSee('AUTH_fake_');
    expect(AuditLog::where('event', 'donor.pii_viewed')->count())->toBe(0);

    // With the permission: the contact details, still never the code that
    // could charge the card again.
    $this->actingAs(financeUser());
    Livewire::test(ViewDonation::class, ['record' => $donation->getRouteKey()])
        ->assertOk()
        ->assertSee('ama@example.test')
        ->assertDontSee('AUTH_fake_');
    expect(AuditLog::where('event', 'donor.pii_viewed')->count())->toBe(1);
});

// ── Actions on a gift ───────────────────────────────────────────────────────

it('resends a receipt with a fresh idempotency key and records who did it', function () {
    $this->actingAs(financeUser(['donations.receipt_reissue']));
    $donation = settledGift();

    expect(ScheduledMessage::where('template_key', 'donation.receipt')->count())->toBe(1);

    Livewire::test(ViewDonation::class, ['record' => $donation->getRouteKey()])
        ->callAction('resendReceipt')
        ->assertNotified();

    expect(ScheduledMessage::where('template_key', 'donation.receipt')->count())->toBe(2)
        ->and(AuditLog::where('event', 'receipt.resent')->exists())->toBeTrue();
});

it('marks a settled payment reconciled, once, by somebody who may', function () {
    $donation = settledGift();

    $this->actingAs(financeUser());
    Livewire::test(ViewDonation::class, ['record' => $donation->getRouteKey()])->assertActionHidden('reconcile');

    $this->actingAs(financeUser(['payments.reconcile']));
    Livewire::test(ViewDonation::class, ['record' => $donation->getRouteKey()])
        ->callAction('reconcile')
        ->assertActionHidden('reconcile');

    expect($donation->transaction->fresh()->reconciled_at)->not->toBeNull()
        ->and(AuditLog::where('event', 'donation.reconciled')->count())->toBe(1);
});

it('appends a dated, signed note without touching the gift', function () {
    $this->actingAs($user = financeUser());
    $donation = settledGift();

    Livewire::test(ViewDonation::class, ['record' => $donation->getRouteKey()])
        ->callAction('note', ['note' => 'Called to say thank you.']);

    $fresh = $donation->fresh();

    expect($fresh->notes)->toContain($user->name.': Called to say thank you.')
        ->and($fresh->amount->toMinor())->toBe(5000)
        ->and($fresh->status)->toBe(DonationStatus::Completed);
});

// ── Refunds: two people ─────────────────────────────────────────────────────

it('requests a refund from the gift and approves it on the Refunds screen, by a different person', function () {
    $donation = settledGift();
    $requester = financeUser(['donations.refund']);
    $approver = financeUser(['donations.refund']);

    $this->actingAs($requester);
    Livewire::test(ViewDonation::class, ['record' => $donation->getRouteKey()])
        ->callAction('refund', ['amount' => '50.00', 'reason' => 'Gave twice by mistake.']);

    $refund = Refund::first();

    expect($refund->status)->toBe(Refund::STATUS_REQUESTED)
        ->and($refund->amount->toMinor())->toBe(5000)
        ->and($donation->fresh()->status)->toBe(DonationStatus::Completed)
        ->and(AuditLog::where('event', 'refund.requested')->exists())->toBeTrue();

    // The requester may not approve their own request. The button is there,
    // the model says no, and the refund stays where it was.
    Livewire::test(ListRefunds::class)
        ->callAction(TestAction::make('approve')->table($refund))
        ->assertNotified();
    expect($refund->fresh()->status)->toBe(Refund::STATUS_REQUESTED);

    $this->actingAs($approver);
    Livewire::test(ListRefunds::class)
        ->callAction(TestAction::make('approve')->table($refund));

    expect($refund->fresh()->status)->toBe(Refund::STATUS_PROCESSED)
        ->and($refund->fresh()->approved_by)->toBe($approver->getKey())
        ->and($donation->fresh()->status)->toBe(DonationStatus::Refunded)
        ->and($donation->cause->fresh()->raisedAmount()->toMinor())->toBe(0)
        ->and(AuditLog::where('event', 'refund.approved')->exists())->toBeTrue()
        ->and(AuditLog::where('event', 'refund.processed')->exists())->toBeTrue();
});

it('hides the refund request from somebody without donations.refund', function () {
    $this->actingAs(financeUser());
    $donation = settledGift();

    Livewire::test(ViewDonation::class, ['record' => $donation->getRouteKey()])->assertActionHidden('refund');
});

it('cancels a requested refund without sending anything', function () {
    $donation = settledGift();
    $refund = app(RefundService::class)->request($donation->transaction, Money::ofMinor(1000), 'Changed mind.', financeUser());

    $this->actingAs(financeUser());
    Livewire::test(ListRefunds::class)->callAction(TestAction::make('cancel')->table($refund));

    expect($refund->fresh()->status)->toBe(Refund::STATUS_CANCELLED)
        ->and($donation->fresh()->status)->toBe(DonationStatus::Completed);
});

// ── Offline gifts ───────────────────────────────────────────────────────────

it('records a cash gift through the offline service, receipts it, and audits it', function () {
    $this->actingAs(financeUser(['donations.record_offline']));

    Livewire::test(CreateDonation::class)
        ->fillForm([
            'amount' => '120.00',
            'offline_method' => 'cash',
            'received_on' => now()->toDateString(),
            'donor_name' => 'Kofi Boateng',
            'donor_email' => 'kofi@example.test',
            'acknowledge' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $donation = Donation::first();

    expect($donation->status)->toBe(DonationStatus::Completed)
        ->and($donation->amount->toMinor())->toBe(12000)
        ->and($donation->transaction->gateway)->toBe('offline')
        ->and($donation->receipt)->not->toBeNull()
        ->and($donation->cause->fresh()->raisedAmount()->toMinor())->toBe(12000)
        ->and(ScheduledMessage::where('template_key', 'donation.receipt')->count())->toBe(1)
        ->and(AuditLog::where('event', 'donation.recorded_offline')->exists())->toBeTrue();
});

it('refuses a cheque with no number', function () {
    $this->actingAs(financeUser(['donations.record_offline']));

    Livewire::test(CreateDonation::class)
        ->fillForm([
            'amount' => '120.00',
            'offline_method' => 'cheque',
            'received_on' => now()->toDateString(),
            'donor_name' => 'Kofi Boateng',
        ])
        ->call('create')
        ->assertHasFormErrors(['offline_reference']);

    expect(Donation::count())->toBe(0);
});

it('hides offline recording from somebody without the permission', function () {
    $this->actingAs(financeUser());
    expect(DonationResource::canCreate())->toBeFalse();

    $this->actingAs(financeUser(['donations.record_offline']));
    expect(DonationResource::canCreate())->toBeTrue();
});

// ── Regular gifts ───────────────────────────────────────────────────────────

it('lets staff pause and cancel a regular gift but never change its amount', function () {
    $this->actingAs(financeUser(['subscriptions.manage']));

    $this->post(route('donate.store'), ['amount' => '50.00', 'donor_name' => 'Ama Mensah', 'donor_email' => 'ama@example.test', 'donor_phone' => '0241234567', 'consent' => '1', 'frequency' => 'monthly']);
    app(FakeGateway::class)->deliverWebhook(Donation::first()->transaction);
    $subscription = Subscription::first();

    $page = Livewire::test(ViewSubscription::class, ['record' => $subscription->getRouteKey()])
        ->assertActionDoesNotExist('amount')
        ->callAction('pause', ['reason' => 'Donor asked for a break.']);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Paused);

    $page->callAction('resume');
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);

    $page->callAction('cancel', ['reason' => 'Donor asked by phone.']);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->fresh()->amount->toMinor())->toBe(5000);
});

// ── Webhooks ────────────────────────────────────────────────────────────────

it('replays a stored webhook without counting the gift twice', function () {
    $this->actingAs(financeUser(['payments.replay_webhook']));
    $donation = settledGift();
    $event = PaymentWebhookEvent::first();

    Livewire::test(ListPaymentWebhookEvents::class)
        ->callAction(TestAction::make('replay')->table($event));

    expect($donation->fresh()->status)->toBe(DonationStatus::Completed)
        ->and($donation->cause->fresh()->raisedAmount()->toMinor())->toBe(5000)
        ->and($donation->fresh()->donor->donation_count)->toBe(1)
        ->and(ScheduledMessage::where('template_key', 'donation.receipt')->count())->toBe(1);
});

it('never offers to replay a webhook whose signature failed', function () {
    $this->actingAs(financeUser(['payments.replay_webhook']));

    $this->post(route('donate.store'), ['amount' => '50.00', 'donor_name' => 'Ama Mensah', 'donor_email' => 'ama@example.test', 'donor_phone' => '0241234567', 'consent' => '1']);
    $donation = Donation::first();

    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 42, 'reference' => $donation->transaction->gateway_reference, 'status' => 'success', 'amount' => 5000, 'currency' => 'GHS']]);
    $this->call('POST', route('webhooks.paystack'), [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => 'not-a-real-signature', 'CONTENT_TYPE' => 'application/json'], $body);

    $event = PaymentWebhookEvent::first();
    expect($event->signature_valid)->toBeFalse();

    Livewire::test(ListPaymentWebhookEvents::class)
        ->assertActionHidden(TestAction::make('replay')->table($event));

    expect($donation->fresh()->status)->toBe(DonationStatus::Pending);
});

// ── Merging donors ──────────────────────────────────────────────────────────

it('merges a duplicate donor: gifts move, blanks fill, totals recount, the duplicate is removed', function () {
    $first = settledGift(['donor_email' => 'ama@work.test', 'donor_phone' => '0241234567']);
    $second = settledGift(['donor_email' => 'ama@home.test', 'donor_phone' => '0209876543', 'amount' => '30.00', 'donor_address' => '12 Ring Road', 'donor_city' => 'Bolgatanga']);

    expect(Donor::count())->toBe(2);

    $survivor = $first->donor;
    $duplicate = $second->donor;

    $moved = app(DonorMerger::class)->merge($survivor, $duplicate, financeUser(['donors.merge']));

    $survivor = $survivor->fresh();

    expect($moved['donations'])->toBe(1)
        ->and($survivor->donation_count)->toBe(2)
        ->and($survivor->total_donated_minor)->toBe(8000)
        ->and($survivor->email)->toBe('ama@work.test')
        ->and($survivor->address)->toBe('12 Ring Road')
        ->and($second->fresh()->donor_id)->toBe($survivor->getKey())
        ->and(Donor::count())->toBe(1)
        ->and(Donor::withTrashed()->find($duplicate->getKey())->notes)->toContain('Merged into donor '.$survivor->getKey())
        ->and(AuditLog::where('event', 'donor.merged')->exists())->toBeTrue();
});

it('moves the duplicate tags to the survivor without doubling the ones they share', function () {
    $first = settledGift();
    $second = settledGift(['donor_email' => 'other@example.test', 'donor_phone' => '0209876543']);

    $gala = Tag::findOrCreateByName('Gala 2026');
    $church = Tag::findOrCreateByName('Church network');

    $first->donor->tags()->attach([$gala->id]);
    $second->donor->tags()->attach([$gala->id, $church->id]);

    app(DonorMerger::class)->merge($first->donor, $second->donor);

    expect($first->donor->fresh()->tags->pluck('name')->sort()->values()->all())->toBe(['Church network', 'Gala 2026']);
});

it('refuses to merge a donor into themselves', function () {
    $donor = settledGift()->donor;

    expect(fn () => app(DonorMerger::class)->merge($donor, $donor))->toThrow(InvalidArgumentException::class);
});

it('offers the merge only to donors.merge and runs it from the donor screen', function () {
    $first = settledGift();
    $second = settledGift(['donor_email' => 'other@example.test', 'donor_phone' => '0209876543']);

    $this->actingAs(financeUser());
    Livewire::test(ViewDonor::class, ['record' => $first->donor->getRouteKey()])->assertActionHidden('merge');

    $this->actingAs(financeUser(['donors.merge']));
    Livewire::test(ViewDonor::class, ['record' => $first->donor->getRouteKey()])
        ->callAction('merge', ['duplicate_id' => $second->donor->getKey()])
        ->assertNotified();

    expect(Donor::count())->toBe(1)
        ->and($first->donor->fresh()->donation_count)->toBe(2);
});

// ── Reports ─────────────────────────────────────────────────────────────────

it('reports completed gifts only, by the date the money arrived', function () {
    $completed = settledGift();
    $this->post(route('donate.store'), ['amount' => '999.00', 'donor_name' => 'Pending Person', 'donor_email' => 'pending@example.test', 'donor_phone' => '0209876543', 'consent' => '1']);

    $reports = GivingReports::between(now()->startOfMonth(), now());
    $summary = $reports->summary();

    expect($summary['raised']->toMinor())->toBe(5000)
        ->and($summary['gifts'])->toBe(1)
        ->and($summary['donors'])->toBe(1)
        ->and($summary['average']->toMinor())->toBe(5000)
        ->and($reports->byCause()->first()['label'])->toBe($completed->cause->title)
        ->and($reports->byChannel()->first()['label'])->toBe('card')
        ->and($reports->byPeriod('month')->first()['period'])->toBe(now()->format('Y-m'))
        ->and($reports->acquisition()['new'])->toBe(1);

    // Outside the window: nothing.
    expect(GivingReports::between(now()->subYear(), now()->subYear()->addDay())->summary()['gifts'])->toBe(0);
});

it('counts a refund out of the total and into the refunded figure', function () {
    $donation = settledGift();
    $refund = app(RefundService::class)->request($donation->transaction, Money::ofMinor(5000), 'Duplicate.', financeUser());
    app(RefundService::class)->approveAndExecute($refund, financeUser());

    $summary = GivingReports::between(now()->startOfMonth(), now())->summary();

    expect($summary['raised']->toMinor())->toBe(0)
        ->and($summary['refunded']->toMinor())->toBe(5000);
});

it('knows what is settled and not yet reconciled', function () {
    settledGift();
    settledGift(['donor_email' => 'b@example.test', 'donor_phone' => '0209876543', 'amount' => '20.00']);

    $before = GivingReports::between(now()->startOfMonth(), now())->reconciliation();
    expect($before['unreconciled'])->toBe(2)->and($before['unreconciled_amount']->toMinor())->toBe(7000);

    Donation::first()->transaction->forceFill(['reconciled_at' => now()])->save();

    $after = GivingReports::between(now()->startOfMonth(), now())->reconciliation();
    expect($after['unreconciled'])->toBe(1)->and($after['reconciled'])->toBe(1);
});

it('draws the reports page and lets the quick ranges move the window', function () {
    $this->actingAs(financeUser());
    settledGift();

    Livewire::test(GivingReportsPage::class)
        ->assertOk()
        ->assertSee('GH₵ 50.00')
        ->call('setRange', 'year')
        ->assertSet('granularity', 'month')
        ->assertSet('from', now()->startOfYear()->toDateString());
});

it('runs reconciliation from the reports page only for payments.reconcile', function () {
    $this->actingAs(financeUser());
    Livewire::test(GivingReportsPage::class)->assertActionHidden('reconcile');

    $this->actingAs(financeUser(['payments.reconcile']));
    Livewire::test(GivingReportsPage::class)
        ->callAction('reconcile')
        ->assertNotified();

    expect(AuditLog::where('event', 'reconciliation.run')->exists())->toBeTrue();
});

// ── The banner ──────────────────────────────────────────────────────────────

it('names the payment mode from the driver and the key prefix', function () {
    config(['payments.driver' => 'fake']);
    expect(PaymentMode::current())->toBe(PaymentMode::Fake);

    config(['payments.driver' => 'paystack', 'payments.paystack.secret_key' => 'sk_test_abc']);
    expect(PaymentMode::current())->toBe(PaymentMode::Test);

    config(['payments.driver' => 'paystack', 'payments.paystack.secret_key' => 'sk_live_abc']);
    expect(PaymentMode::current())->toBe(PaymentMode::Live)
        ->and(PaymentMode::current()->isLive())->toBeTrue();
});

it('writes test mode across every admin page and says nothing in live mode', function () {
    $this->actingAs(financeUser()->fresh());

    config(['payments.driver' => 'paystack', 'payments.paystack.secret_key' => 'sk_test_abc']);
    $this->get('/'.config('admin.path'))->assertOk()->assertSee('data-payment-mode="test"', escape: false)->assertSee('Test mode');
    $this->get('/'.config('admin.path').'/donations')->assertOk()->assertSee('data-payment-mode="test"', escape: false);

    config(['payments.driver' => 'paystack', 'payments.paystack.secret_key' => 'sk_live_abc']);
    $this->get('/'.config('admin.path'))->assertOk()->assertDontSee('data-payment-mode=', escape: false);
});
