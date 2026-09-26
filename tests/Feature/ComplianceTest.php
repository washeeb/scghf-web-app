<?php

declare(strict_types=1);

use App\Models\LegalHold;
use App\Models\Media;
use App\Models\Order;
use App\Models\RetentionLogEntry;
use App\Models\TaxApproval;
use App\Models\User;
use App\Support\RetentionRunner;
use App\Support\TaxDeductibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A stand-in for the uploaded GRA approval letter.
 *
 * Inserted directly rather than through Media Library, which wants a real file
 * on disk. All these tests need is a row the foreign key can point at.
 */
function approvalDocumentId(): int
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

/** A minimal approval, valid today. */
function validApproval(array $overrides = []): TaxApproval
{
    return TaxApproval::create(array_merge([
        'approval_type' => TaxApproval::TYPE_SECTION_97,
        'reference' => 'GRA/CHAR/2026/'.fake()->numerify('####'),
        'tin' => 'C00'.fake()->numerify('#######'),
        'issued_on' => now()->subMonths(6),
        'expires_on' => now()->addYear(),
        'status' => TaxApproval::STATUS_ACTIVE,
        'document_id' => approvalDocumentId(),
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════════════════════
//  Tax deductibility — Act 896 ss.97 and 100
// ═══════════════════════════════════════════════════════════════════════════

it('does NOT treat donations as deductible merely because the foundation exists', function () {
    // The whole point. No approval on file means no deductibility claim,
    // regardless of how the foundation is registered or what a cause says.
    $tax = app(TaxDeductibility::class);

    expect(TaxApproval::count())->toBe(0)
        ->and($tax->isEnabled())->toBeFalse()
        ->and($tax->qualifies((object) ['is_tax_deductible' => true]))->toBeFalse()
        ->and($tax->receiptStatement((object) ['is_tax_deductible' => true]))->toBeNull();
});

it('enables deductibility wording only with a current approval', function () {
    $tax = app(TaxDeductibility::class);
    validApproval();
    $tax->flushCache();

    expect($tax->isEnabled())->toBeTrue()
        ->and($tax->qualifies((object) ['is_tax_deductible' => true]))->toBeTrue();
});

it('disables deductibility automatically the day the approval expires', function () {
    $tax = app(TaxDeductibility::class);
    $approval = validApproval(['expires_on' => now()->addDays(2)]);
    $tax->flushCache();

    expect($tax->isEnabled())->toBeTrue();

    $this->travel(3)->days();
    $tax->flushCache();

    // Nobody touched anything. The date passing is what disables it — this must
    // not depend on a scheduler having run.
    expect($approval->fresh()->isCurrentlyValid())->toBeFalse()
        ->and($tax->isEnabled())->toBeFalse()
        ->and($tax->qualifies((object) ['is_tax_deductible' => true]))->toBeFalse();
});

it('is valid through the whole of the expiry day, not until midnight before it', function () {
    $approval = validApproval(['expires_on' => now()->addDay()]);

    $this->travel(1)->day();

    // An approval expiring "on 30 June" is valid on 30 June.
    expect($approval->fresh()->isCurrentlyValid())->toBeTrue();
});

it('stops immediately when an approval is revoked', function () {
    $tax = app(TaxDeductibility::class);
    $approval = validApproval();
    $tax->flushCache();
    expect($tax->isEnabled())->toBeTrue();

    $approval->revoke('Withdrawn by GRA pending review');
    $tax->flushCache();

    expect($tax->isEnabled())->toBeFalse();
});

it('refuses to activate an approval with no document attached', function () {
    // An active approval with no letter is an assertion, and exactly what an
    // auditor would ask about.
    TaxApproval::create([
        'reference' => 'GRA/X/1', 'tin' => 'C001234567',
        'issued_on' => now()->subMonth(), 'expires_on' => now()->addYear(),
        'status' => TaxApproval::STATUS_ACTIVE,
    ]);
})->throws(RuntimeException::class);

it('refuses an expiry on or before the issue date', function () {
    validApproval(['issued_on' => now(), 'expires_on' => now()->subDay()]);
})->throws(RuntimeException::class);

it('requires the cause to qualify as well as the organisation being approved', function () {
    $tax = app(TaxDeductibility::class);
    validApproval();
    $tax->flushCache();

    // A s.97 approval makes the ORGANISATION approved. It does not make every
    // activity it runs a worthwhile cause.
    expect($tax->isEnabled())->toBeTrue()
        ->and($tax->qualifies((object) ['is_tax_deductible' => false]))->toBeFalse()
        ->and($tax->qualifies(null))->toBeFalse();
});

it('never promises the donor a deduction', function () {
    $tax = app(TaxDeductibility::class);
    validApproval();
    $tax->flushCache();

    $statement = $tax->receiptStatement((object) ['is_tax_deductible' => true]);

    expect($statement)->toHaveKeys(['citation', 'disclaimer'])
        ->and($statement['citation'])->toContain('section 97')
        // The receipt confirms the gift and cites the approval. Whether a
        // deduction is allowed is the GRA determination on the donor's return.
        ->and($statement['disclaimer'])->toContain('does not guarantee')
        ->and($statement['disclaimer'])->toContain('Ghana Revenue Authority');
});

it('cites the approval reference and validity on the receipt', function () {
    $approval = validApproval(['reference' => 'GRA/CHAR/2026/0042', 'expires_on' => now()->addYear()]);

    expect($approval->citation())
        ->toContain('GRA/CHAR/2026/0042')
        ->toContain('Act 896')
        ->toContain('valid to');
});

it('flags approvals nearing expiry', function () {
    validApproval(['expires_on' => now()->addDays(20)]);

    expect(app(TaxDeductibility::class)->expiringSoon())->toHaveCount(1);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Retention — Act 843
// ═══════════════════════════════════════════════════════════════════════════

it('defines a purpose for every retention class', function () {
    // A retention period is only defensible if it is tied to a purpose. A class
    // without one is a period somebody guessed.
    foreach (config('compliance.retention.classes') as $key => $policy) {
        expect($policy)->toHaveKeys(['label', 'action', 'purpose'], "class [{$key}]")
            ->and($policy['purpose'])->not->toBeEmpty("class [{$key}] has no stated purpose");
    }
});

it('matches the agreed retention periods', function (string $class, ?int $months, string $action) {
    $policy = config("compliance.retention.classes.{$class}");

    expect($policy['months'])->toBe($months)
        ->and($policy['action'])->toBe($action);
})->with([
    ['beneficiary_application_declined', 24, 'delete'],
    ['beneficiary_application_withdrawn', 12, 'delete'],
    ['beneficiary_case_record', 72, 'de_identify'],
    ['beneficiary_sensitive_document', 24, 'delete'],
    ['financial_record', 72, 'retain'],
    ['anonymised_statistics', null, 'retain'],
]);

it('keeps sensitive documents for far less time than the case they support', function () {
    $case = config('compliance.retention.classes.beneficiary_case_record.months');
    $sensitive = config('compliance.retention.classes.beneficiary_sensitive_document.months');

    // A medical report proving eligibility has served its purpose when the case
    // closes. Keeping it six years alongside the financial record would be
    // retention without a purpose.
    expect($sensitive)->toBeLessThan($case);
});

it('never schedules financial or anonymised records for destruction', function (string $class) {
    // Six years is a statutory MINIMUM, not a deletion date.
    expect(config("compliance.retention.classes.{$class}.action"))->toBe('retain');
})->with(['financial_record', 'anonymised_statistics']);

// ── Legal holds ──────────────────────────────────────────────────────────────

it('finds a hold placed on a specific record', function () {
    $user = User::factory()->create();

    LegalHold::create([
        'title' => 'Dispute', 'reason' => 'Live complaint',
        'holdable_type' => $user->getMorphClass(), 'holdable_id' => $user->getKey(),
    ]);

    expect(LegalHold::covers($user, 'beneficiary_case_record'))->not->toBeNull();
});

it('finds a hold placed on a whole retention class', function () {
    $user = User::factory()->create();

    LegalHold::create([
        'title' => 'Audit 2026', 'reason' => 'External audit',
        'hold_type' => 'audit', 'retention_class' => 'beneficiary_case_record',
    ]);

    // "Hold every beneficiary case record" is how such instructions actually
    // arrive; enumerating them by hand would be error-prone.
    expect(LegalHold::covers($user, 'beneficiary_case_record'))->not->toBeNull()
        ->and(LegalHold::covers($user, 'beneficiary_application_declined'))->toBeNull();
});

it('finds a hold scoped to a programme', function () {
    $user = User::factory()->create();

    LegalHold::create([
        'title' => 'Education programme review', 'reason' => 'Investigation',
        'retention_class' => 'beneficiary_case_record', 'scope_key' => 'programme:2025-education',
    ]);

    expect(LegalHold::covers($user, 'beneficiary_case_record', 'programme:2025-education'))->not->toBeNull()
        ->and(LegalHold::covers($user, 'beneficiary_case_record', 'programme:2025-health'))->toBeNull();
});

it('stops covering records once released', function () {
    $user = User::factory()->create();
    $hold = LegalHold::create([
        'title' => 'H', 'reason' => 'R', 'retention_class' => 'beneficiary_case_record',
    ]);

    expect(LegalHold::covers($user, 'beneficiary_case_record'))->not->toBeNull();

    $hold->release('Matter concluded');

    expect(LegalHold::covers($user, 'beneficiary_case_record'))->toBeNull();
});

it('flags holds past their review date', function () {
    LegalHold::create([
        'title' => 'Old hold', 'reason' => 'R',
        'retention_class' => 'beneficiary_case_record',
        'review_on' => now()->subMonth(),
    ]);

    // A hold nobody reviews keeps data indefinitely, which is its own Act 843
    // failure in the other direction.
    expect(LegalHold::needingReview()->count())->toBe(1);
});

it('gives every hold a quotable reference', function () {
    $hold = LegalHold::create(['title' => 'H', 'reason' => 'R']);

    expect($hold->reference)->toStartWith('HOLD-'.now()->format('Y'));
});

// ── The audit log ────────────────────────────────────────────────────────────

it('refuses to let the retention log be edited or deleted', function () {
    $entry = RetentionLogEntry::create([
        'retention_class' => 'beneficiary_case_record',
        'subject_type' => 'App\\Models\\User', 'subject_id' => 1,
        'action' => 'delete', 'created_at' => now(),
    ]);

    // A deletion log an administrator can edit proves nothing.
    expect(fn () => $entry->update(['action' => 'reviewed']))->toThrow(RuntimeException::class);
    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
});

it('records a digest rather than the personal data itself', function () {
    $entry = RetentionLogEntry::create([
        'retention_class' => 'beneficiary_case_record',
        'subject_type' => 'App\\Models\\User', 'subject_id' => 1,
        'subject_digest' => hash('sha256', 'ama@example.com'),
        'action' => 'de_identify', 'created_at' => now(),
    ]);

    // The log outlives the data it describes, so it must not itself be a copy
    // of that data.
    expect($entry->subject_digest)->not->toContain('ama@example.com')
        ->and(strlen($entry->subject_digest))->toBe(64)
        ->and($entry->wasDestructive())->toBeTrue();
});

// ── The runner ───────────────────────────────────────────────────────────────

it('defaults to a dry run', function () {
    $summary = app(RetentionRunner::class)->run();

    // Destroying data must be an explicit act, never the default.
    expect($summary['dry_run'])->toBeTrue()
        ->and($summary['run_id'])->not->toBeEmpty();
});

it('has a batch ceiling configured as a safety valve', function () {
    // A run that suddenly wants to destroy thousands of records is far more
    // likely to be a wrong anchor date than a real backlog.
    expect(config('compliance.retention.max_records_per_run'))->toBeGreaterThan(0)
        ->and(config('compliance.retention.grace_period_days'))->toBeGreaterThan(0);
});

it('keeps the log longer than the records it accounts for', function () {
    $longest = collect(config('compliance.retention.classes'))
        ->pluck('months')->filter()->max();

    expect(config('compliance.retention.log_retention_months'))->toBeGreaterThanOrEqual($longest);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Shop policy
// ═══════════════════════════════════════════════════════════════════════════

it('lists only the approved shop categories', function () {
    $items = collect(config('compliance.shop.approved_categories'))
        ->flatMap(fn (array $c) => $c['items'])
        ->map(fn (string $i) => strtolower($i));

    expect($items)->toContain('caps', 'tote bags', 'wristbands', 'keyholders', 'pens', 'mugs', 'books')
        ->and(config('compliance.shop.approved_categories'))->toHaveKey('campaign');
});

it('flags regulated goods that need a separate regulatory review', function (string $name) {
    $keywords = config('compliance.shop.prohibited_keywords');
    $hit = collect($keywords)->contains(fn (string $k) => str_contains(strtolower($name), $k));

    expect($hit)->toBeTrue("[{$name}] should be flagged for FDA Ghana review");
})->with([
    'Paracetamol tablets',
    'Multivitamin supplement',
    'Herbal remedy tonic',
    'Hand sanitiser',
    'Body lotion cream',
    'Malaria test kit',
]);

it('does not flag ordinary merchandise', function (string $name) {
    $keywords = config('compliance.shop.prohibited_keywords');
    $hit = collect($keywords)->contains(fn (string $k) => str_contains(strtolower($name), $k));

    expect($hit)->toBeFalse("[{$name}] should not be flagged");
})->with([
    'Branded polo shirt',
    'Reusable water bottle',
    'A5 notebook',
    'Foundation tote bag',
    'Devotional journal',
]);

it('forbids a charitable acknowledgement for a shop order', function () {
    // A purchase is consideration for goods, not a gift. Issuing a donation
    // receipt for one misrepresents the transaction to the customer and the GRA.
    expect(config('compliance.tax.never_acknowledge_payable_types'))
        ->toContain(Order::class);
});

it('allows an acknowledgement for something that is not a forbidden payable', function () {
    $tax = app(TaxDeductibility::class);

    expect($tax->mayAcknowledge(new stdClass))->toBeTrue();
});
