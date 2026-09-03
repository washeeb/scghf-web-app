<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\DonationItem;
use App\Models\DonationReceipt;
use App\Models\Donor;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Payments\RecurringGivingService;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Recurring giving
|--------------------------------------------------------------------------
|
| Two things these tests exist to hold:
|
|   1. a cron run that overlaps or repeats never charges a donor twice
|   2. an authorization that cannot be reused is REPORTED, not silently skipped
|
| The second is the Ghana caveat made operational. Mobile money is how most
| donors here pay, and mobile-money authorizations are not reliably reusable —
| so a donor can believe they have set up a monthly gift that will never be
| taken. Failing quietly would leave nobody to tell them.
|
*/

beforeEach(function () {
    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');

    $this->recurring = app(RecurringGivingService::class);
});

/** A settled first gift with a reusable card authorization. */
function firstGift(bool $reusable = true, string $authCode = 'AUTH_reusable_1'): Donation
{
    $cause = Cause::factory()->create();
    $donor = Donor::factory()->create();

    $donation = Donation::factory()->create([
        'donor_id' => $donor->id,
        'cause_id' => $cause->id,
        'amount' => 5_000,
    ]);

    DonationItem::create([
        'donation_id' => $donation->id,
        'cause_id' => $cause->id,
        'amount' => 5_000,
    ]);

    $donation->recalculateDeductible();
    $donation->forceFill(['status' => DonationStatus::Completed, 'paid_at' => now()])->save();

    $transaction = $donation->transaction()->create([
        'gateway' => 'fake',
        'gateway_reference' => $donation->reference,
        'amount' => 5_000,
        'currency' => 'GHS',
        'status' => 'success',
        'channel' => $reusable ? 'card' : 'mobile_money',
        'customer_email' => $donor->email,
    ]);

    /*
     * forceFill, because `authorization_code` and `paid_at` are deliberately
     * NOT fillable on PaymentTransaction — they are written by settle() and
     * recordInstrument(), never by mass assignment from a request. Writing them
     * here the same way keeps the fixture honest about the real path.
     */
    $transaction->forceFill([
        'authorization_code' => $authCode,
        'paid_at' => now(),
        'verified_at' => now(),
    ])->save();

    return $donation->fresh();
}

// ═══════════════════════════════════════════════════════════════════════════
//  Establishing a commitment
// ═══════════════════════════════════════════════════════════════════════════

it('establishes a subscription from a gift that actually went through', function () {
    $subscription = $this->recurring->establish(firstGift());

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->reference)->toStartWith('SCGHF-S-')
        ->and($subscription->amount)->toEqualPesewas(5_000)
        // The first cycle is the gift already made, so the next is a full
        // interval away rather than today.
        ->and($subscription->next_charge_on->toDateString())
        ->toBe(now()->addMonthNoOverflow()->toDateString());
});

it('refuses to establish one from a gift that has not completed', function () {
    // A standing commitment with no authorization behind it is a promise the
    // system cannot keep.
    $donation = Donation::factory()->create();

    $this->recurring->establish($donation);
})->throws(RuntimeException::class, 'actually went through');

it('refuses to establish one with nobody to charge', function () {
    $donation = firstGift();
    $donation->forceFill(['donor_id' => null])->save();

    $this->recurring->establish($donation->fresh());
})->throws(RuntimeException::class, 'needs a donor record');

it('links the first gift to the commitment it started', function () {
    $donation = firstGift();
    $subscription = $this->recurring->establish($donation);

    expect($donation->fresh()->subscription_id)->toBe($subscription->id);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Reusability — the Ghana caveat
// ═══════════════════════════════════════════════════════════════════════════

it('treats a card authorization as reusable', function () {
    $subscription = $this->recurring->establish(firstGift(reusable: true));

    expect($subscription->authorization_reusable)->toBeTrue()
        ->and($subscription->blockedReason())->toBeNull();
});

it('does NOT assume a mobile money authorization is reusable', function () {
    // The conservative default. Assuming reusability and being wrong fails a
    // donor's gift every month; assuming the opposite produces a report
    // somebody can act on.
    $subscription = $this->recurring->establish(firstGift(reusable: false));

    expect($subscription->authorization_reusable)->toBeFalse()
        ->and($subscription->blockedReason())->toContain('not reusable');
});

it('believes the gateway when it says an authorization is reusable', function () {
    $donation = firstGift(reusable: false);

    // Paystack's own flag beats our channel heuristic.
    $donation->transaction->forceFill([
        'response_payload' => ['data' => ['authorization' => ['reusable' => true]]],
    ])->save();

    $subscription = $this->recurring->establish($donation->fresh());

    expect($subscription->authorization_reusable)->toBeTrue();
});

it('reports a blocked subscription rather than passing over it', function () {
    $subscription = $this->recurring->establish(firstGift(reusable: false));
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $summary = $this->recurring->chargeDue();

    expect($summary['blocked'])->toBe(1)
        ->and($summary['charged'])->toBe(0)
        ->and(implode(' ', $summary['detail']))->toContain('BLOCKED');
});

it('records a skipped cycle so the donor history has no hole in it', function () {
    // "Nothing happened in April, and here is why" is an answer. A missing row
    // is not.
    $subscription = $this->recurring->establish(firstGift(reusable: false));
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $this->recurring->chargeDue();

    $charge = SubscriptionCharge::first();

    expect($charge->status)->toBe(SubscriptionCharge::STATUS_SKIPPED)
        ->and($charge->failure_reason)->toContain('not reusable');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Charging
// ═══════════════════════════════════════════════════════════════════════════

it('charges a due subscription and produces a real gift', function () {
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $summary = $this->recurring->chargeDue();

    expect($summary['charged'])->toBe(1)
        ->and($subscription->fresh()->charge_count)->toBe(1)
        ->and($subscription->fresh()->totalCharged())->toEqualPesewas(5_000)
        // Two gifts now: the original and this cycle's.
        ->and(Donation::completed()->count())->toBe(2);
});

it('moves the next charge date on by exactly one interval', function () {
    $subscription = $this->recurring->establish(firstGift());
    $due = now()->toDateString();
    $subscription->forceFill(['next_charge_on' => $due])->save();

    $this->recurring->chargeDue();

    expect($subscription->fresh()->next_charge_on->toDateString())
        ->toBe(Carbon::parse($due)->addMonthNoOverflow()->toDateString());
});

it('does not skip February for a gift set up on the 31st', function () {
    // addMonthsNoOverflow, not addMonths: overflowing to 3 March would move
    // every subsequent charge date with it.
    $subscription = Subscription::factory()->create(['interval' => Subscription::INTERVAL_MONTHLY]);

    expect($subscription->advanceFrom(Carbon::parse('2026-01-31'))->toDateString())
        ->toBe('2026-02-28');
});

it('never charges the same cycle twice, however often cron runs', function () {
    // Cron on shared hosting can overlap and can run late.
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $this->recurring->chargeDue();
    $this->recurring->chargeDue();
    $this->recurring->chargeDue();

    expect($subscription->fresh()->charge_count)->toBe(1)
        ->and(SubscriptionCharge::count())->toBe(1);
});

it('issues an acknowledgement for every cycle, not just the first', function () {
    // A donor giving monthly is entitled to twelve acknowledgements.
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $this->recurring->chargeDue();

    expect(DonationReceipt::count())->toBe(1)
        ->and(DonationReceipt::first()->donation->subscription_id)->toBe($subscription->id);
});

it('snapshots deductibility fresh for each cycle', function () {
    // An approval that lapsed since the first gift must not carry forward.
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $this->recurring->chargeDue();

    $cycle = Donation::where('subscription_id', $subscription->id)
        ->where('id', '!=', $subscription->donations()->min('id'))
        ->latest('id')->first();

    expect($cycle->items->first()->is_tax_deductible)->toBeFalse();
});

it('charges nothing on a dry run', function () {
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $summary = $this->recurring->chargeDue(execute: false);

    expect($summary['due'])->toBe(1)
        ->and($summary['charged'])->toBe(0)
        ->and(SubscriptionCharge::count())->toBe(0);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Failure and recovery
// ═══════════════════════════════════════════════════════════════════════════

it('marks a subscription as failing rather than cancelled after one decline', function () {
    // A card that expired is not a donor who stopped giving.
    $subscription = $this->recurring->establish(firstGift(authCode: 'AUTH_DECLINE_me'));
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $this->recurring->chargeDue();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Failing)
        ->and($subscription->fresh()->failed_attempts)->toBe(1)
        ->and(SubscriptionCharge::first()->status)->toBe(SubscriptionCharge::STATUS_FAILED);
});

it('suspends after repeated failures rather than retrying for ever', function () {
    // Hammering an expired card every month is how a charity ends up on a card
    // network's watch list.
    $subscription = $this->recurring->establish(firstGift(authCode: 'AUTH_DECLINE_me'));

    foreach (range(1, 3) as $ignored) {
        $subscription->fresh()->forceFill(['next_charge_on' => now()->toDateString()])->save();
        $this->recurring->chargeDue();
    }

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Paused);
});

it('clears the failure count on any success', function () {
    // Three failures last year should not suspend a subscription that has been
    // paying fine since.
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill([
        'failed_attempts' => 2,
        'status' => SubscriptionStatus::Failing,
        'next_charge_on' => now()->toDateString(),
    ])->save();

    $this->recurring->chargeDue();

    expect($subscription->fresh()->failed_attempts)->toBe(0)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('leaves a paused subscription alone', function () {
    $subscription = $this->recurring->establish(firstGift());
    $subscription->pause('Donor asked to hold it over Christmas.');
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $summary = $this->recurring->chargeDue();

    expect($summary['due'])->toBe(0)
        ->and($summary['charged'])->toBe(0);
});

it('refuses to resume a cancelled commitment', function () {
    // The donor set it up and ended it. Restarting it without asking would be
    // taking money they stopped.
    $subscription = $this->recurring->establish(firstGift());
    $subscription->cancel('No longer able to give.');

    $subscription->resume();
})->throws(RuntimeException::class, 'cannot be resumed');

it('resumes a paused commitment', function () {
    $subscription = $this->recurring->establish(firstGift());
    $subscription->pause();
    $subscription->resume();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

it('never charges a gateway-driven subscription itself', function () {
    // Paystack owns that schedule; charging it here would take the money twice.
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill([
        'driver' => Subscription::DRIVER_GATEWAY,
        'next_charge_on' => now()->toDateString(),
    ])->save();

    expect($subscription->fresh()->isDueToday())->toBeFalse()
        ->and($this->recurring->chargeDue()['due'])->toBe(0);
});

it('applies the same amount checks to a recurring charge as a one-off', function () {
    // A recurring gift gets no weaker verification simply because nobody is
    // watching it happen.
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $this->recurring->chargeDue();

    $cycle = Donation::where('subscription_id', $subscription->id)->latest('id')->first();

    expect($cycle->transaction->amount)->toEqualPesewas(5_000)
        ->and($cycle->transaction->amount_paid_minor)->toBe(5_000);
});

it('reports the console summary', function () {
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $this->artisan('scghf:charge-recurring', ['--execute' => true])
        ->expectsOutputToContain('Charged: 1')
        ->assertSuccessful();
});

it('is a dry run unless told otherwise', function () {
    // It takes money from donors' accounts. A command that does that on a bare
    // invocation is one keystroke from a mistake.
    $subscription = $this->recurring->establish(firstGift());
    $subscription->forceFill(['next_charge_on' => now()->toDateString()])->save();

    $this->artisan('scghf:charge-recurring')
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect($subscription->fresh()->charge_count)->toBe(0);
});
