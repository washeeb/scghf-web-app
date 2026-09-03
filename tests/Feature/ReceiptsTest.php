<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\DonationItem;
use App\Models\DonationReceipt;
use App\Models\Media;
use App\Models\TaxApproval;
use App\Models\User;
use App\Payments\ReceiptIssuer;
use App\ValueObjects\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Donor acknowledgements
|--------------------------------------------------------------------------
|
| The document a donor hands to the GRA. What these tests protect:
|
|   1. numbers are sequential per financial year with NO gaps
|   2. every figure and sentence is snapshotted, never re-rendered
|   3. one receipt per gift, ever
|   4. the tax wording covers the DEDUCTIBLE SUBTOTAL, not the gross
|
*/

beforeEach(function () {
    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');

    $this->issuer = app(ReceiptIssuer::class);
});

function receiptApproval(array $overrides = []): TaxApproval
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
        'reference' => 'CG/CHAR/2026/0417',
        'tin' => 'C0001234567',
        'issued_on' => now()->subMonths(6),
        'expires_on' => now()->addYear(),
        'status' => TaxApproval::STATUS_ACTIVE,
        'document_id' => $document->id,
    ], $overrides));
}

/**
 * A completed gift with one item, optionally deductible.
 *
 * Built in the order the real flow uses: the donation and its items are written
 * while the gift is still PENDING, the deductible subtotal is computed there,
 * and only then does settlement mark it completed. Doing it the other way round
 * trips the append-only guard, which is the guard doing its job.
 */
function completedGift(bool $deductible = false, string $major = '250.00'): Donation
{
    $cause = $deductible
        ? Cause::factory()->taxDeductible()->create(['title' => 'BrightPath Scholarships'])
        : Cause::factory()->create(['title' => 'BrightPath Scholarships']);

    $donation = Donation::factory()->create([
        'cause_id' => $cause->id,
        'amount' => Money::ofMajor($major),
        'donor_name' => 'Kwabena Mensah',
        'donor_email' => 'kwabena@example.com',
    ]);

    DonationItem::create([
        'donation_id' => $donation->id,
        'cause_id' => $cause->id,
        'amount' => $donation->amount_minor,
        ...DonationItem::snapshotDeductibility($cause),
    ]);

    $donation->recalculateDeductible();

    $donation->forceFill(['status' => DonationStatus::Completed, 'paid_at' => now()])->save();

    return $donation->fresh();
}

// ═══════════════════════════════════════════════════════════════════════════
//  Numbering
// ═══════════════════════════════════════════════════════════════════════════

it('numbers receipts sequentially within a financial year', function () {
    $first = $this->issuer->issue(completedGift());
    $second = $this->issuer->issue(completedGift());

    $year = now()->year;

    expect($first->receipt_number)->toBe(sprintf('SCGHF-R-%d-000001', $year))
        ->and($second->receipt_number)->toBe(sprintf('SCGHF-R-%d-000002', $year))
        ->and($second->sequence)->toBe(2);
});

it('restarts the series each financial year', function () {
    // The foundation's financial year starts 1 January, so the year in the
    // number is the calendar year.
    $lastYear = completedGift();
    $lastYear->forceFill(['paid_at' => now()->subYear()])->save();

    $thisYear = completedGift();

    $a = $this->issuer->issue($lastYear->fresh());
    $b = $this->issuer->issue($thisYear);

    expect($a->sequence)->toBe(1)
        ->and($b->sequence)->toBe(1)
        ->and($a->financial_year)->toBe(now()->subYear()->year)
        ->and($b->financial_year)->toBe(now()->year);
});

it('returns a burnt number to the series when issue fails', function () {
    // An AUTO_INCREMENT would keep the number and leave a gap. An auditor
    // expects every number in the series to be accounted for.
    setting()->set('general.tin', '{{TIN}}');

    try {
        $this->issuer->issue(completedGift());
    } catch (RuntimeException) {
        // Expected: no TIN, no document.
    }

    setting()->set('general.tin', 'C0001234567');

    expect($this->issuer->issue(completedGift())->sequence)->toBe(1)
        ->and(DonationReceipt::count())->toBe(1);
});

/*
 * NOT TESTED HERE: allocateNumber() refuses to run outside a transaction.
 *
 * RefreshDatabase wraps every test in one, so DB::transactionLevel() is never
 * zero in this suite and the guard cannot be reached. It is kept in the model
 * regardless — it protects the gapless series in production, which is where it
 * matters — but pretending to cover it with a test that cannot fail would be
 * worse than saying so.
 */

it('allocates under a row lock, so two issues cannot take the same number', function () {
    $first = $this->issuer->issue(completedGift());
    $second = $this->issuer->issue(completedGift());

    expect($first->sequence)->not->toBe($second->sequence)
        ->and(DonationReceipt::pluck('sequence')->unique())->toHaveCount(2);
});

it('never issues two receipts for the same gift', function () {
    $donation = completedGift();

    $first = $this->issuer->issue($donation);
    $second = $this->issuer->issue($donation->fresh());

    // Idempotent: settlement is applied more than once, and each must not burn
    // another number.
    expect($second->id)->toBe($first->id)
        ->and(DonationReceipt::count())->toBe(1);
});

it('refuses a second receipt row at the database level too', function () {
    $donation = completedGift();
    $this->issuer->issue($donation);

    DB::table('donation_receipts')->insert([
        'ulid' => (string) Str::ulid(),
        'donation_id' => $donation->id,
        'receipt_number' => 'SCGHF-R-2026-999999',
        'financial_year' => 2026,
        'sequence' => 999999,
        'issued_on' => now()->toDateString(),
        'donor_name' => 'x',
        'organisation_name' => 'x',
        'organisation_tin' => 'x',
        'amount_minor' => 1,
        'amount_in_words' => 'One Pesewa',
        'cause' => 'x',
        'donated_on' => now()->toDateString(),
        'statement' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(UniqueConstraintViolationException::class);

// ═══════════════════════════════════════════════════════════════════════════
//  What may be receipted
// ═══════════════════════════════════════════════════════════════════════════

it('refuses to acknowledge a gift that has not completed', function () {
    // Pending, failed and needs-review gifts have not actually been received.
    $donation = Donation::factory()->create();

    $this->issuer->issue($donation);
})->throws(RuntimeException::class, 'Only a completed gift');

it('refuses to issue while the foundation TIN is an unfilled placeholder', function () {
    setting()->set('general.tin', '{{TIN}}');

    $this->issuer->issue(completedGift());
})->throws(RuntimeException::class, 'Foundation TIN');

// ═══════════════════════════════════════════════════════════════════════════
//  Snapshotting
// ═══════════════════════════════════════════════════════════════════════════

it('freezes the wording so a lapsed approval cannot rewrite an issued document', function () {
    receiptApproval();
    $receipt = $this->issuer->issue(completedGift(deductible: true));

    expect($receipt->cites_approval)->toBeTrue()
        ->and($receipt->approval_reference)->toBe('CG/CHAR/2026/0417');

    // The approval lapses. The document already in the donor's hands says what
    // it said.
    TaxApproval::first()->forceFill(['expires_on' => now()->subDay()])->save();

    $receipt->refresh();

    expect($receipt->cites_approval)->toBeTrue()
        ->and($receipt->approval_reference)->toBe('CG/CHAR/2026/0417')
        ->and(implode(' ', $receipt->paragraphs()))->toContain('section 97');
});

it('freezes the donor name against a later correction', function () {
    $receipt = $this->issuer->issue(completedGift());

    $receipt->donation->donor->update(['name' => 'Corrected Name']);

    expect($receipt->fresh()->donor_name)->toBe('Kwabena Mensah');
});

it('stores the amount in words as printed', function () {
    $receipt = $this->issuer->issue(completedGift(major: '1234.56'));

    expect($receipt->amount_in_words)
        ->toBe('One Thousand Two Hundred and Thirty-Four Ghana Cedis and Fifty-Six Pesewas');
});

it('refuses to alter an issued receipt', function () {
    $receipt = $this->issuer->issue(completedGift());

    $receipt->update(['amount' => 1]);
})->throws(RuntimeException::class, 'cannot be altered');

it('allows the PDF and delivery record to be added afterwards', function () {
    // Annotating delivery is not altering the document.
    $receipt = $this->issuer->issue(completedGift());

    $receipt->markSent('kwabena@example.com');

    expect($receipt->fresh()->isSent())->toBeTrue()
        ->and($receipt->fresh()->sent_to)->toBe('kwabena@example.com');
});

it('refuses to delete a receipt', function () {
    $this->issuer->issue(completedGift())->delete();
})->throws(RuntimeException::class, 'never deleted');

// ═══════════════════════════════════════════════════════════════════════════
//  The tax wording
// ═══════════════════════════════════════════════════════════════════════════

it('issues a plain receipt with no tax wording when no approval is held', function () {
    $receipt = $this->issuer->issue(completedGift(deductible: true));

    // The cause is flagged, but the foundation holds no approval — so the
    // document acknowledges the gift and claims nothing.
    expect($receipt->cites_approval)->toBeFalse()
        ->and($receipt->supportsTaxClaim())->toBeFalse()
        ->and(implode(' ', $receipt->paragraphs()))
        ->not->toContain('section 97')
        ->not->toContain('section 100');
});

it('cites the approval and the disclaimer when the gift qualifies', function () {
    receiptApproval();
    $receipt = $this->issuer->issue(completedGift(deductible: true));

    $text = implode(' ', $receipt->paragraphs());

    expect($receipt->supportsTaxClaim())->toBeTrue()
        ->and($text)->toContain('section 97 of the Income Tax Act, 2015 (Act 896)')
        ->and($text)->toContain('section 100 of the Income Tax Act, 2015 (Act 896)')
        // Never a promise. The GRA decides.
        ->and($text)->toContain('determination of the Ghana Revenue Authority');
});

it('states the deductible subtotal, not the gross, on a mixed gift', function () {
    receiptApproval();

    $deductible = Cause::factory()->taxDeductible()->create(['title' => 'Scholarships']);
    $other = Cause::factory()->create(['title' => 'Staff costs']);

    $donation = Donation::factory()->create([
        'cause_id' => $deductible->id,
        'amount' => 50_000,
        'donor_name' => 'Kwabena Mensah',
    ]);

    DonationItem::create([
        'donation_id' => $donation->id, 'cause_id' => $deductible->id, 'amount' => 30_000,
        ...DonationItem::snapshotDeductibility($deductible),
    ]);
    DonationItem::create([
        'donation_id' => $donation->id, 'cause_id' => $other->id, 'amount' => 20_000,
        ...DonationItem::snapshotDeductibility($other),
    ]);

    $donation->recalculateDeductible();
    $donation->forceFill(['status' => DonationStatus::Completed, 'paid_at' => now()])->save();

    $receipt = $this->issuer->issue($donation->fresh());

    // The gift was GH₵ 500; only GH₵ 300 of it is evidence for a claim.
    // Stating the gross would overstate it on a document going to the GRA.
    expect($receipt->amount)->toEqualPesewas(50_000)
        ->and($receipt->deductible_amount)->toEqualPesewas(30_000)
        ->and($receipt->non_deductible_amount)->toEqualPesewas(20_000)
        ->and(implode(' ', $receipt->paragraphs()))->toContain('GH₵ 300.00');
});

it('names every destination of a split gift', function () {
    // The GRA claim form asks for the worthwhile cause, so the donor has to be
    // able to answer it.
    $one = Cause::factory()->create(['title' => 'Scholarships']);
    $two = Cause::factory()->create(['title' => 'Health outreach']);

    $donation = Donation::factory()->completed()->create(['cause_id' => $one->id, 'amount' => 50_000]);

    DonationItem::create(['donation_id' => $donation->id, 'cause_id' => $one->id, 'amount' => 30_000]);
    DonationItem::create(['donation_id' => $donation->id, 'cause_id' => $two->id, 'amount' => 20_000]);

    $receipt = $this->issuer->issue($donation->fresh());

    expect($receipt->cause)->toContain('Scholarships')
        ->and($receipt->cause)->toContain('Health outreach');
});

it('carries the foundation TIN and an authorised signatory', function () {
    $receipt = $this->issuer->issue(completedGift());

    expect($receipt->organisation_tin)->toBe('C0001234567')
        ->and($receipt->organisation_name)->toBe("St. Cecilia's Greater Hope Foundations")
        ->and($receipt->authentication)->not->toBeEmpty();
});

it('records the payment reference so the gift can be traced', function () {
    $receipt = $this->issuer->issue(completedGift());

    expect($receipt->payment_reference)->toStartWith('SCGHF-D-');
});
