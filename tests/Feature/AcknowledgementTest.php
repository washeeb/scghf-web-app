<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\TaxApproval;
use App\Models\User;
use App\Support\Acknowledgement;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The donor acknowledgement
|--------------------------------------------------------------------------
|
| The document a donor hands to the GRA with a section 100 claim. Two things
| have to stay true of it in every test below:
|
|   1. it never asserts a deduction — that is the GRA's determination, on the
|      donor's own return, and the Foundation has no standing to promise it; and
|   2. it never cites an approval the Foundation did not hold on the day the
|      donation was received.
|
*/

function ackDocumentId(): int
{
    return Media::create([
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
    ])->id;
}

function ackApproval(array $overrides = []): TaxApproval
{
    return TaxApproval::create(array_merge([
        'approval_type' => TaxApproval::TYPE_SECTION_97,
        'reference' => 'CG/CHAR/2026/0417',
        'tin' => 'C0001234567',
        'issued_on' => now()->subMonths(6),
        'expires_on' => now()->addYear(),
        'status' => TaxApproval::STATUS_ACTIVE,
        'document_id' => ackDocumentId(),
    ], $overrides));
}

/** A cause the trustees have marked as a qualifying worthwhile cause. */
function ackCause(bool $deductible = true, ?int $approvalId = null): object
{
    return new class($deductible, $approvalId)
    {
        public function __construct(
            public bool $is_tax_deductible,
            public ?int $tax_approval_id,
        ) {}
    };
}

function ackContext(array $overrides = []): array
{
    return array_merge([
        'receipt_number' => 'ACK-2026-000418',
        'donor_name' => 'Kwabena Mensah',
        'amount' => Money::ofMajor('1234.56'),
        'donated_on' => now()->subMonth(),
        'cause' => 'Legacy of Love — school fees fund',
        'payment_reference' => 'PSK_a1b2c3d4e5',
        'authentication' => 'Executive Director, for and on behalf of the Board',
        'cause_model' => ackCause(),
    ], $overrides);
}

beforeEach(function () {
    // The seeded TIN is the unfilled {{TIN}} placeholder, which Settings reports
    // as absent. A real value is needed before any document can be issued.
    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');

    $this->ack = app(Acknowledgement::class);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Naming and wording
// ═══════════════════════════════════════════════════════════════════════════

it('is titled an acknowledgement, not a tax-deductible receipt', function () {
    // The Foundation acknowledges a contribution; the deduction is the donor's
    // separate claim. Titling it otherwise asserts something not ours to assert.
    ackApproval();

    expect($this->ack->for(ackContext())['title'])
        ->toBe('ACKNOWLEDGEMENT OF CONTRIBUTION/DONATION TO A WORTHWHILE CAUSE');
});

it('states the section 97 approval and the section 100 purpose separately', function () {
    ackApproval();

    $document = $this->ack->for(ackContext());

    expect($document['paragraphs'])->toHaveCount(3)
        ->and($document['paragraphs'][0])
        ->toContain('approved by the Commissioner-General of the Ghana Revenue Authority')
        ->toContain('section 97 of the Income Tax Act, 2015 (Act 896)')
        ->toContain('Notice of Approval CG/CHAR/2026/0417')
        ->and($document['paragraphs'][2])
        ->toContain('section 100 of the Income Tax Act, 2015 (Act 896)');
});

it('never guarantees the deduction', function () {
    ackApproval();

    $document = $this->ack->for(ackContext());
    $text = implode(' ', $document['paragraphs']);

    expect($text)->toContain('remains subject to the applicable requirements and determination of the Ghana Revenue Authority')
        ->and($text)->not->toContain('tax deductible')
        ->and($text)->not->toContain('tax-deductible');
});

it('carries the amount in figures and in words, and they agree', function () {
    ackApproval();

    $document = $this->ack->for(ackContext());

    expect($document['paragraphs'][1])
        ->toContain('GH₵ 1,234.56')
        ->toContain('One Thousand Two Hundred and Thirty-Four Ghana Cedis and Fifty-Six Pesewas')
        ->and($document['fields']['amount'])->toBe('GH₵ 1,234.56');
});

it('names the worthwhile cause the gift was made towards', function () {
    ackApproval();

    expect($this->ack->for(ackContext())['paragraphs'][1])
        ->toContain('Legacy of Love — school fees fund');
});

// ═══════════════════════════════════════════════════════════════════════════
//  The approval gate
// ═══════════════════════════════════════════════════════════════════════════

it('issues a plain receipt with no tax wording when no approval is held', function () {
    // No approval on file. The donor is still entitled to evidence of the gift;
    // what they are not entitled to is wording the Foundation cannot support.
    $document = $this->ack->for(ackContext());

    expect($document['cites_approval'])->toBeFalse()
        ->and($document['paragraphs'])->toHaveCount(1)
        ->and($document['approval'])->toBeNull()
        ->and(implode(' ', $document['paragraphs']))
        ->not->toContain('section 97')
        ->not->toContain('section 100');
});

it('stops citing the approval the day it expires', function () {
    ackApproval([
        'issued_on' => now()->subYears(2),
        'expires_on' => now()->subDay(),
    ]);

    // Today's donation, yesterday's expiry. Nothing may be claimed.
    $document = $this->ack->for(ackContext(['donated_on' => now()]));

    expect($document['cites_approval'])->toBeFalse()
        ->and($document['paragraphs'])->toHaveCount(1);
});

it('cites the approval that was valid on the DONATION date, not today', function () {
    // The approval lapsed a month ago; the gift was received a year ago, while
    // it was current. Reprinting that receipt must not rewrite what was true.
    ackApproval([
        'issued_on' => now()->subYears(3),
        'expires_on' => now()->subMonth(),
    ]);

    $document = $this->ack->for(ackContext(['donated_on' => now()->subYear()]));

    expect($document['cites_approval'])->toBeTrue()
        ->and($document['approval']['reference'])->toBe('CG/CHAR/2026/0417');
});

it('does not let a donation acquire an approval granted after it was received', function () {
    // The mirror of the case above, and the more dangerous one: a gift received
    // before the Foundation was approved was never covered by that approval.
    ackApproval([
        'issued_on' => now()->subMonth(),
        'expires_on' => now()->addYear(),
    ]);

    $document = $this->ack->for(ackContext(['donated_on' => now()->subYear()]));

    expect($document['cites_approval'])->toBeFalse();
});

it('will not cite a revoked approval even for a donation received while it stood', function () {
    $approval = ackApproval(['issued_on' => now()->subYears(2)]);
    $approval->revoke('Withdrawn by the Commissioner-General.');

    $document = $this->ack->for(ackContext(['donated_on' => now()->subYear()]));

    expect($document['cites_approval'])->toBeFalse();
});

it('omits tax wording for a cause the trustees have not marked as qualifying', function () {
    // A section 97 approval makes the ORGANISATION approved. It does not make
    // every activity it runs a worthwhile cause.
    ackApproval();

    $document = $this->ack->for(ackContext(['cause_model' => ackCause(deductible: false)]));

    expect($document['cites_approval'])->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Completeness — a legal document does not get blank lines
// ═══════════════════════════════════════════════════════════════════════════

it('carries every field the GRA claim needs', function () {
    ackApproval();

    $fields = $this->ack->for(ackContext())['fields'];

    expect($fields['receipt_number'])->toBe('ACK-2026-000418')
        ->and($fields['donor_name'])->toBe('Kwabena Mensah')
        ->and($fields['organisation_name'])->toBe("St. Cecilia's Greater Hope Foundations")
        ->and($fields['organisation_tin'])->toBe('C0001234567')
        ->and($fields['amount_in_words'])->not->toBeEmpty()
        ->and($fields['payment_reference'])->toBe('PSK_a1b2c3d4e5')
        ->and($fields['approval_reference'])->toBe('CG/CHAR/2026/0417')
        ->and($fields['approval_validity'])->toContain(' to ')
        ->and($fields['authentication'])->not->toBeEmpty();
});

it('refuses to issue while the foundation TIN is still an unfilled placeholder', function () {
    // Settings reports an unfilled {{TIN}} as absent, so this is what happens
    // until the real TIN is entered — a refusal, not a document with a hole.
    setting()->set('general.tin', '{{TIN}}');
    ackApproval();

    $this->ack->for(ackContext());
})->throws(RuntimeException::class, 'Foundation TIN');

it('refuses to issue without an authorised signature', function () {
    ackApproval();

    $this->ack->for(ackContext(['authentication' => '']));
})->throws(RuntimeException::class, 'Authorised signature or seal');

it('does not demand approval details on a plain receipt', function () {
    // No approval held, so there is no approval to state. Demanding one would
    // make an honest document impossible to issue.
    $document = $this->ack->for(ackContext());

    expect($document['fields']['approval_reference'])->toBe('');
});

// ═══════════════════════════════════════════════════════════════════════════
//  The shop prohibition
// ═══════════════════════════════════════════════════════════════════════════

it('never acknowledges a purchase as a charitable donation', function () {
    ackApproval();

    /*
     * App\Models\Order does not exist until Module 5, so a forbidden type that
     * DOES exist stands in for it here. The rule under test is the wiring —
     * that a forbidden payable reaching this builder is refused outright rather
     * than quietly producing a donation receipt. That the shop's Order is on
     * the forbidden list is asserted in ComplianceTest.
     *
     * A purchase is consideration for goods. Acknowledging it as a gift would
     * misstate the transaction to the customer and to the GRA.
     */
    config(['compliance.tax.never_acknowledge_payable_types' => [Media::class]]);

    $this->ack->for(ackContext(['payable' => new Media]));
})->throws(RuntimeException::class, 'never be issued');

it('insists on a Money so the figures and the words cannot diverge', function () {
    ackApproval();

    $this->ack->for(ackContext(['amount' => 1234.56]));
})->throws(RuntimeException::class);

it('records the issue date separately from the donation date', function () {
    ackApproval();

    $document = $this->ack->for(ackContext([
        'donated_on' => Carbon::parse('2026-03-13'),
        'issued_on' => Carbon::parse('2026-04-02'),
    ]));

    expect($document['fields']['donated_on'])->toBe('13 March 2026')
        ->and($document['fields']['issued_on'])->toBe('2 April 2026');
});
