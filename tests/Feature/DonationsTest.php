<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Enums\PaymentStatus;
use App\Models\Cause;
use App\Models\Division;
use App\Models\Donation;
use App\Models\DonationItem;
use App\Models\Donor;
use App\Models\Media;
use App\Models\PaymentWebhookEvent;
use App\Models\TaxApproval;
use App\Models\User;
use App\Payments\DonationService;
use App\Payments\FakeGateway;
use App\Payments\FeeCalculator;
use App\Payments\PaymentManager;
use App\ValueObjects\Money;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Donations
|--------------------------------------------------------------------------
|
| The rules these tests hold, in order of what they would cost to get wrong:
|
|   1. a gift's parts always sum exactly to the whole
|   2. covering the fee leaves the foundation with the full intended amount
|   3. deductibility is snapshotted at the time of the gift and never rewritten
|   4. a completed donation is append-only
|   5. settlement is idempotent — a replayed webhook never double-counts
|
*/

beforeEach(function () {
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    $this->donations = app(DonationService::class);
    $this->payments = app(PaymentManager::class);
});

function graApproval(array $overrides = []): TaxApproval
{
    $document = Media::create([
        'model_type' => User::class,
        'model_id' => User::factory()->create()->id,
        'collection_name' => 'tax-approvals',
        'name' => 'gra-approval',
        'file_name' => 'gra-approval.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'public',
        'size' => 1024,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);

    return TaxApproval::create(array_merge([
        'approval_type' => TaxApproval::TYPE_SECTION_97,
        'reference' => 'CG/CHAR/2026/'.fake()->numerify('####'),
        'tin' => 'C0001234567',
        'issued_on' => now()->subMonths(6),
        'expires_on' => now()->addYear(),
        'status' => TaxApproval::STATUS_ACTIVE,
        'document_id' => $document->id,
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function giftInput(array $overrides = []): array
{
    return array_merge([
        'amount' => Money::ofMajor('250.00'),
        'donor_name' => 'Kwabena Mensah',
        'donor_email' => 'kwabena@example.com',
        'consent_email' => true,
        'consent_text' => 'I agree to receive a receipt by email.',
    ], $overrides);
}

function settleDonation(Donation $donation): void
{
    $transaction = $donation->transaction;

    $body = json_encode([
        'event' => 'charge.success',
        'data' => [
            'id' => random_int(1_000_000, 9_999_999),
            'reference' => $transaction->gateway_reference,
            'status' => 'success',
            'amount' => $transaction->amount->toMinor(),
            'currency' => 'GHS',
            'fees' => 488,
            'channel' => 'mobile_money',
            'paid_at' => now()->toIso8601String(),
        ],
    ], JSON_THROW_ON_ERROR);

    test()->call(
        'POST',
        config('payments.paystack.webhook_path'),
        [], [], [],
        ['HTTP_X_PAYSTACK_SIGNATURE' => FakeGateway::sign($body), 'CONTENT_TYPE' => 'application/json'],
        $body,
    );

    app(PaymentManager::class)->processWebhook(PaymentWebhookEvent::latest('id')->first());
}

// ═══════════════════════════════════════════════════════════════════════════
//  Destination
// ═══════════════════════════════════════════════════════════════════════════

it('sends a gift with no chosen appeal to the General Fund', function () {
    $this->seed([DivisionSeeder::class, CauseSeeder::class]);

    $donation = $this->donations->create(giftInput());

    expect($donation->cause->is_general_fund)->toBeTrue();
});

it('refuses to build a donation when no destination exists at all', function () {
    // A payment the foundation has taken and cannot account for is the one
    // failure worth stopping the request over.
    $this->donations->create(giftInput());
})->throws(RuntimeException::class, 'No General Fund cause exists');

it('denormalises the division from the cause for reporting', function () {
    $division = Division::factory()->create();
    $cause = Cause::factory()->create(['division_id' => $division->id]);

    $donation = $this->donations->create(giftInput(['cause' => $cause]));

    expect($donation->division_id)->toBe($division->id);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Designated giving — the parts must sum to the whole
// ═══════════════════════════════════════════════════════════════════════════

it('creates an item even for a single-destination gift', function () {
    // Uniform structure beats a nullable special case: the rarely-exercised
    // code path is the one that would be wrong.
    $cause = Cause::factory()->create();

    $donation = $this->donations->create(giftInput(['cause' => $cause]));

    expect($donation->items)->toHaveCount(1)
        ->and($donation->items->first()->amount)->toEqualPesewas(25_000);
});

it('splits a gift across causes so the parts sum exactly to the whole', function () {
    $one = Cause::factory()->create();
    $two = Cause::factory()->create();

    $donation = $this->donations->create(giftInput([
        'amount' => Money::ofMajor('500.00'),
        'designations' => [
            ['cause' => $one, 'amount' => Money::ofMajor('300.00')],
            ['cause' => $two, 'amount' => Money::ofMajor('200.00')],
        ],
    ]));

    expect($donation->items)->toHaveCount(2)
        ->and($donation->items->sum('amount_minor'))->toBe(50_000);

    // The reconciliation that makes designated giving trustworthy.
    $donation->assertItemsReconcile();
});

it('spreads a fee-covered top-up across the designations without losing a pesewa', function () {
    $one = Cause::factory()->create();
    $two = Cause::factory()->create();

    $donation = $this->donations->create(giftInput([
        'amount' => Money::ofMajor('300.00'),
        'cover_fee' => true,
        'designations' => [
            ['cause' => $one, 'amount' => Money::ofMajor('200.00')],
            ['cause' => $two, 'amount' => Money::ofMajor('100.00')],
        ],
    ]));

    // Money::allocate() hands the rounding remainder out one pesewa at a time,
    // which is exactly why this still reconciles.
    expect($donation->items->sum('amount_minor'))->toBe($donation->amount_minor);

    $donation->assertItemsReconcile();
});

it('catches a split that does not reconcile', function () {
    $donation = Donation::factory()->create(['amount' => 50_000]);

    DonationItem::create([
        'donation_id' => $donation->id,
        'cause_id' => $donation->cause_id,
        'amount' => 30_000,
    ]);

    $donation->load('items')->assertItemsReconcile();
})->throws(RuntimeException::class, 'does not reconcile');

it('refuses a zero-amount item', function () {
    $donation = Donation::factory()->create();

    DonationItem::create([
        'donation_id' => $donation->id,
        'cause_id' => $donation->cause_id,
        'amount' => 0,
    ]);
})->throws(RuntimeException::class, 'positive amount');

// ═══════════════════════════════════════════════════════════════════════════
//  Covering the fee
// ═══════════════════════════════════════════════════════════════════════════

it('grosses up so the foundation nets what the donor intended', function () {
    $cause = Cause::factory()->create();
    $intended = Money::ofMajor('100.00');

    $donation = $this->donations->create(giftInput([
        'amount' => $intended,
        'cause' => $cause,
        'cover_fee' => true,
    ]));

    $fees = app(FeeCalculator::class);

    expect($donation->amount->greaterThan($intended))->toBeTrue()
        ->and($donation->fee_covered_by_donor)->toBeTrue()
        // The point: net of the gateway's cut, the foundation still has the
        // full GH₵ 100.
        ->and($fees->netOf($donation->amount)->greaterThanOrEqual($intended))->toBeTrue();
});

it('charges the plain amount when the donor does not cover the fee', function () {
    $cause = Cause::factory()->create();

    $donation = $this->donations->create(giftInput(['cause' => $cause, 'cover_fee' => false]));

    expect($donation->amount)->toEqualPesewas(25_000)
        ->and($donation->fee_covered_by_donor)->toBeFalse();
});

it('honours the config switch that turns fee cover off', function () {
    config(['payments.donations.allow_fee_cover' => false]);
    $cause = Cause::factory()->create();

    $donation = $this->donations->create(giftInput(['cause' => $cause, 'cover_fee' => true]));

    expect($donation->amount)->toEqualPesewas(25_000);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Limits
// ═══════════════════════════════════════════════════════════════════════════

it('refuses a gift smaller than the fee it would cost to accept', function () {
    Cause::factory()->create();

    $this->donations->create(giftInput(['amount' => Money::ofMajor('1.00')]));
})->throws(RuntimeException::class, 'smallest donation');

it('sends an unusually large gift to the foundation rather than the form', function () {
    // An extra zero is one keystroke away on a public form.
    Cause::factory()->create();

    $this->donations->create(giftInput(['amount' => Money::ofMajor('500000.00')]));
})->throws(RuntimeException::class, 'arranged with the foundation directly');

it('refuses a float amount outright', function () {
    Cause::factory()->create();

    $this->donations->create(giftInput(['amount' => 250.00]));
})->throws(RuntimeException::class, 'must be a Money');

// ═══════════════════════════════════════════════════════════════════════════
//  Tax deductibility — snapshotted, never recomputed
// ═══════════════════════════════════════════════════════════════════════════

it('snapshots a gift as non-deductible while no GRA approval is held', function () {
    // The cause is flagged, but the flag alone is not enough.
    $cause = Cause::factory()->taxDeductible()->create();

    $donation = $this->donations->create(giftInput(['cause' => $cause]));

    expect($donation->items->first()->is_tax_deductible)->toBeFalse()
        ->and($donation->deductibleAmount())->toEqualPesewas(0);
});

it('snapshots a gift as deductible when both conditions hold', function () {
    graApproval();
    $cause = Cause::factory()->taxDeductible()->create();

    $donation = $this->donations->create(giftInput(['cause' => $cause]));

    expect($donation->items->first()->is_tax_deductible)->toBeTrue()
        ->and($donation->deductibleAmount())->toEqualPesewas(25_000)
        // Which approval it was claimed under, so an old acknowledgement can
        // still be explained years later.
        ->and($donation->items->first()->tax_approval_id)->not->toBeNull();
});

it('handles a gift that is part deductible and part not', function () {
    graApproval();
    $deductible = Cause::factory()->taxDeductible()->create();
    $other = Cause::factory()->create();

    $donation = $this->donations->create(giftInput([
        'amount' => Money::ofMajor('500.00'),
        'designations' => [
            ['cause' => $deductible, 'amount' => Money::ofMajor('300.00')],
            ['cause' => $other, 'amount' => Money::ofMajor('200.00')],
        ],
    ]));

    // A sum over items, not a flag on the parent.
    expect($donation->deductibleAmount())->toEqualPesewas(30_000)
        ->and($donation->nonDeductibleAmount())->toEqualPesewas(20_000)
        ->and($donation->isPartlyDeductible())->toBeTrue();
});

it('refuses to rewrite a deductibility snapshot afterwards', function () {
    // Otherwise an acknowledgement issued last year could quietly stop matching
    // the record it was issued from.
    graApproval();
    $cause = Cause::factory()->taxDeductible()->create();

    $donation = $this->donations->create(giftInput(['cause' => $cause]));

    $donation->items->first()->update(['is_tax_deductible' => false]);
})->throws(RuntimeException::class, 'snapshot taken at the time of the gift');

it('keeps an old gift deductible after the approval lapses', function () {
    graApproval();
    $cause = Cause::factory()->taxDeductible()->create();

    $donation = $this->donations->create(giftInput(['cause' => $cause]));

    // The approval expires. What was true when the gift was received stays
    // true on the record of it.
    TaxApproval::first()->forceFill(['expires_on' => now()->subDay()])->save();

    expect($donation->fresh()->items->first()->is_tax_deductible)->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Append-only
// ═══════════════════════════════════════════════════════════════════════════

it('refuses to change the amount on a completed gift', function () {
    $donation = Donation::factory()->completed()->create();

    $donation->update(['amount' => 99_999]);
})->throws(RuntimeException::class, 'append-only');

it('refuses to move a completed gift to another cause', function () {
    $donation = Donation::factory()->completed()->create();

    $donation->update(['cause_id' => Cause::factory()->create()->id]);
})->throws(RuntimeException::class, 'append-only');

it('allows a Finance note on a completed gift', function () {
    // Annotating is not editing. The figures are frozen; the commentary is not.
    $donation = Donation::factory()->completed()->create();

    $donation->update(['notes' => 'Confirmed against the March settlement file.']);

    expect($donation->fresh()->notes)->toContain('March settlement');
});

it('refuses to delete a donation', function () {
    Donation::factory()->create()->delete();
})->throws(RuntimeException::class, 'never deleted');

// ═══════════════════════════════════════════════════════════════════════════
//  Settlement, end to end
// ═══════════════════════════════════════════════════════════════════════════

it('completes a gift and moves every total with it', function () {
    $cause = Cause::factory()->create();

    ['donation' => $donation] = $this->donations->start(giftInput(['cause' => $cause]));

    settleDonation($donation->fresh());

    $donation->refresh();

    expect($donation->status)->toBe(DonationStatus::Completed)
        ->and($donation->paid_at)->not->toBeNull()
        ->and($cause->fresh()->raisedAmount())->toEqualPesewas(25_000)
        ->and($cause->fresh()->donation_count)->toBe(1)
        ->and($donation->donor->fresh()->totalDonated())->toEqualPesewas(25_000);
});

it('records what the foundation actually keeps after the gateway cut', function () {
    $cause = Cause::factory()->create();
    ['donation' => $donation] = $this->donations->start(giftInput(['cause' => $cause]));

    settleDonation($donation->fresh());

    // 488 pesewas came from the payload — the fee the gateway ACTUALLY charged,
    // not our model of it.
    expect($donation->fresh()->fee)->toEqualPesewas(488)
        ->and($donation->fresh()->net)->toEqualPesewas(25_000 - 488);
});

it('never double-counts when the same settlement is applied twice', function () {
    $cause = Cause::factory()->create();
    ['donation' => $donation] = $this->donations->start(giftInput(['cause' => $cause]));

    settleDonation($donation->fresh());

    // Paystack retries; reconciliation re-verifies; an admin re-checks by hand.
    $donation->fresh()->onPaymentSettled($donation->fresh()->transaction);
    $donation->fresh()->onPaymentSettled($donation->fresh()->transaction);

    expect($cause->fresh()->raisedAmount())->toEqualPesewas(25_000)
        ->and($cause->fresh()->donation_count)->toBe(1)
        ->and($donation->donor->fresh()->donation_count)->toBe(1);
});

it('holds a gift for review when the gateway settles the wrong amount', function () {
    $cause = Cause::factory()->create();
    ['donation' => $donation] = $this->donations->start(giftInput(['cause' => $cause]));

    $transaction = $donation->fresh()->transaction;
    $transaction->settle(Money::ofMinor(24_999));
    $donation->fresh()->onPaymentMismatch($transaction->fresh());

    $donation->refresh();

    expect($donation->status)->toBe(DonationStatus::NeedsReview)
        // Not completed, so it never reaches the appeal total...
        ->and($cause->fresh()->raisedAmount())->toEqualPesewas(0)
        // ...and not failed either, because the money may have been taken.
        ->and($donation->status)->not->toBe(DonationStatus::Failed);
});

it('does not let a late failure erase a completed gift', function () {
    $cause = Cause::factory()->create();
    ['donation' => $donation] = $this->donations->start(giftInput(['cause' => $cause]));
    settleDonation($donation->fresh());

    $donation->fresh()->onPaymentFailed($donation->fresh()->transaction);

    expect($donation->fresh()->status)->toBe(DonationStatus::Completed);
});

it('recomputes an appeal total from completed gifts only', function () {
    $cause = Cause::factory()->create();

    Donation::factory()->completed()->count(3)->create(['cause_id' => $cause->id, 'amount' => 10_000]);
    Donation::factory()->count(2)->create(['cause_id' => $cause->id, 'amount' => 10_000]);

    expect($cause->recalculateRaised())->toEqualPesewas(30_000)
        ->and($cause->fresh()->donation_count)->toBe(3);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Donors
// ═══════════════════════════════════════════════════════════════════════════

it('matches a returning donor by email rather than creating a second record', function () {
    $cause = Cause::factory()->create();

    $this->donations->create(giftInput(['cause' => $cause]));
    $this->donations->create(giftInput(['cause' => $cause]));

    expect(Donor::count())->toBe(1);
});

it('matches on a normalised phone, so 024… and +23324… are one person', function () {
    $existing = Donor::factory()->create(['phone' => '+233241234567', 'email' => null]);

    $matched = Donor::matchOrCreate(['name' => 'Ama', 'phone' => '024 123 4567']);

    expect($matched->id)->toBe($existing->id);
});

it('does not merge two donors who happen to share a name', function () {
    // Two people called Kwame Mensah are two people.
    Donor::factory()->create(['name' => 'Kwame Mensah', 'email' => 'one@example.com']);

    $second = Donor::matchOrCreate(['name' => 'Kwame Mensah', 'email' => 'two@example.com']);

    expect(Donor::count())->toBe(2)
        ->and($second->email)->toBe('two@example.com');
});

it('accepts a genuinely anonymous cash gift with no contact details', function () {
    // It happens at events. It means no acknowledgement and no giving history,
    // which is a consequence rather than an error.
    $cause = Cause::factory()->create();

    $donation = $this->donations->create([
        'amount' => Money::ofMajor('50.00'),
        'cause' => $cause,
        'donor_name' => 'Anonymous',
        'is_anonymous' => true,
    ]);

    expect($donation->donor_id)->toBeNull()
        ->and($donation->publicDonorName())->toBe('Anonymous');
});

it('records consent per channel with its evidence', function () {
    $cause = Cause::factory()->create();

    $donation = $this->donations->create(giftInput([
        'cause' => $cause,
        'consent_email' => true,
        'consent_sms' => false,
        'consent_text' => 'I agree to receive updates by email.',
        'consent_ip' => '41.66.1.20',
    ]));

    // Consent to a receipt is not consent to a newsletter, and one boolean
    // cannot express the difference.
    expect($donation->consent_email)->toBeTrue()
        ->and($donation->consent_sms)->toBeFalse()
        ->and($donation->consent_at)->not->toBeNull()
        ->and($donation->donor->mayBeEmailed())->toBeTrue()
        ->and($donation->donor->mayBeTexted())->toBeFalse();
});

it('snapshots the donor details onto the gift', function () {
    // A donor can correct their name later; the acknowledgement already issued
    // said what it said, and the ledger has to still agree with it.
    $cause = Cause::factory()->create();

    $donation = $this->donations->create(giftInput(['cause' => $cause]));

    $donation->donor->update(['name' => 'Corrected Name']);

    expect($donation->fresh()->donor_name)->toBe('Kwabena Mensah');
});

it('hides an anonymous gift from the public wall but not from Finance', function () {
    $donation = Donation::factory()->anonymous()->create(['donor_name' => 'Ama Boateng']);

    expect($donation->publicDonorName())->toBe('Anonymous')
        // Still fully attributed in the record.
        ->and($donation->donor_name)->toBe('Ama Boateng');
});

it('starts a charge and hands back somewhere to send the donor', function () {
    $cause = Cause::factory()->create();

    ['donation' => $donation, 'transaction' => $transaction] = $this->donations->start(
        giftInput(['cause' => $cause])
    );

    expect($transaction->status)->toBe(PaymentStatus::Pending)
        ->and($transaction->authorization_url)->not->toBeNull()
        ->and($transaction->gateway_reference)->toBe($donation->reference)
        ->and($transaction->payable->is($donation))->toBeTrue();
});
