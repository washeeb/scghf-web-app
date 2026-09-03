<?php

declare(strict_types=1);

use App\Models\Beneficiary;
use App\Models\BeneficiaryDocument;
use App\Models\BeneficiaryImpactRecord;
use App\Models\Consent;
use App\Models\LegalHold;
use App\Models\Project;
use App\Models\RetentionLogEntry;
use App\Models\Story;
use App\Support\RetentionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Beneficiaries, consent and the two-dataset design
|--------------------------------------------------------------------------
|
| The most sensitive part of the application. These tests exist to hold three
| lines that are easy to cross by accident:
|
|   1. closing a case starts the retention clock; it does not destroy anything
|   2. nothing identifying crosses into the analytics dataset, ever
|   3. a photograph or a story is not published without consent on file
|
*/

// ═══════════════════════════════════════════════════════════════════════════
//  Classification — every column has been decided about
// ═══════════════════════════════════════════════════════════════════════════

it('classifies every column on the beneficiary table', function () {
    // The point of the map: a field added in two years that nobody classified
    // would survive de-identification untouched. This fails the build instead.
    expect(Beneficiary::unclassifiedColumns())->toBe([]);
});

it('classifies every column on the beneficiary document table', function () {
    expect(BeneficiaryDocument::unclassifiedColumns())->toBe([]);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Case lifecycle — closure is not destruction
// ═══════════════════════════════════════════════════════════════════════════

it('starts the retention clock on closure without destroying anything', function () {
    $beneficiary = Beneficiary::factory()->create();

    $beneficiary->close('assisted');
    $beneficiary->refresh();

    expect($beneficiary->closed_at)->not->toBeNull()
        // Still entirely intact. The record stays lawfully identifiable for the
        // whole retention period.
        ->and($beneficiary->full_name)->not->toBeNull()
        ->and($beneficiary->ghana_card_number)->not->toBeNull()
        ->and($beneficiary->exists)->toBeTrue();
});

it('gives an open case no anchor date, so it is never due', function () {
    // Approved and being assisted, not yet closed. The case-record class is
    // anchored on closed_at, and null means the clock has not started — which
    // is what stops a live case being swept up.
    $beneficiary = Beneficiary::factory()->create(['status' => Beneficiary::STATUS_APPROVED]);

    expect($beneficiary->retentionClass())->toBe('beneficiary_case_record')
        ->and($beneficiary->retentionAnchorDate())->toBeNull();
});

it('anchors an unfinished application on its last activity, not on nothing', function () {
    // A submitted application that stalls is subject to the 12-month
    // incomplete rule. It is not immortal just because it was never decided.
    $beneficiary = Beneficiary::factory()->create(['status' => Beneficiary::STATUS_SUBMITTED]);

    expect($beneficiary->retentionClass())->toBe('beneficiary_application_withdrawn')
        ->and($beneficiary->retentionAnchorDate())->not->toBeNull();
});

it('applies a different retention class to a declined application', function () {
    // 24 months, not 72: there is a decision to defend, but no assistance and
    // no financial trail to keep.
    $declined = Beneficiary::factory()->declined()->create();
    $closed = Beneficiary::factory()->closed()->create();
    $withdrawn = Beneficiary::factory()->withdrawn()->create();

    expect($declined->retentionClass())->toBe('beneficiary_application_declined')
        ->and($closed->retentionClass())->toBe('beneficiary_case_record')
        ->and($withdrawn->retentionClass())->toBe('beneficiary_application_withdrawn');
});

it('anchors each class on its own event', function () {
    $declined = Beneficiary::factory()->declined()->create();
    $withdrawn = Beneficiary::factory()->withdrawn()->create();

    expect($declined->retentionAnchorDate()->timestamp)->toBe($declined->decided_at->timestamp)
        ->and($withdrawn->retentionAnchorDate()->timestamp)->toBe($withdrawn->last_activity_at->timestamp);
});

it('keeps an application alive while somebody is still working on it', function () {
    // last_activity_at is touched on every change, so an incomplete application
    // being edited is never swept up as abandoned.
    $beneficiary = Beneficiary::factory()->withdrawn()->create();
    $before = $beneficiary->last_activity_at;

    $beneficiary->update(['case_notes' => 'Applicant called back.']);

    expect($beneficiary->fresh()->last_activity_at->gt($before))->toBeTrue();
});

it('generates a quotable case reference', function () {
    $beneficiary = Beneficiary::factory()->create();

    expect($beneficiary->case_reference)->toStartWith('SCGHF-B-');
});

// ═══════════════════════════════════════════════════════════════════════════
//  The analytics dataset
// ═══════════════════════════════════════════════════════════════════════════

it('holds no identifying column at all', function (string $column) {
    // The specification of this table is what is ABSENT from it. Adding one of
    // these back has to be a decision somebody makes deliberately.
    expect(Schema::hasColumn('beneficiary_impact_records', $column))
        ->toBeFalse("beneficiary_impact_records must not carry [{$column}]");
})->with([
    'full_name', 'name', 'phone', 'email', 'ghana_card_number', 'address',
    'community', 'latitude', 'longitude', 'date_of_birth', 'photo_id',
    'case_notes', 'application_narrative', 'case_reference', 'payment_reference',
    'assistance_minor', 'assisted_on', 'medical_notes',
]);

it('projects a generalised record when a case closes', function () {
    $beneficiary = Beneficiary::factory()->closed()->create([
        'date_of_birth' => now()->subYears(15)->toDateString(),
        'assisted_on' => '2026-03-13',
        'assistance' => 473_500,
        'community' => 'Asokwa',
        'district' => 'Tamale Metropolitan',
        'region' => 'Northern',
    ]);

    $record = $beneficiary->projectImpactRecord();

    expect($record->age_band)->toBe('13-17')
        // Bands and periods, never exact values: GHS 4,735 on 13 March in a
        // small programme singles out one person.
        ->and($record->assistance_band)->toBe('2,500.00 - 5,000.00')
        ->and($record->assistance_period)->toBe('2026-03')
        ->and($record->district)->toBe('Tamale Metropolitan')
        ->and($record->region)->toBe('Northern');
});

it('never carries the community into the analytics record', function () {
    // District is the finest geography permitted. A village is not.
    $beneficiary = Beneficiary::factory()->closed()->create(['community' => 'Asokwa']);

    $record = $beneficiary->close()->fresh();

    expect($record->getAttributes())->not->toHaveKey('community');
});

it('refreshes rather than duplicating the projection', function () {
    $beneficiary = Beneficiary::factory()->closed()->create();

    $beneficiary->projectImpactRecord();
    $beneficiary->projectImpactRecord();

    expect(BeneficiaryImpactRecord::count())->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Retention expiry — destruction, and what survives it
// ═══════════════════════════════════════════════════════════════════════════

it('destroys the case record and leaves the statistics standing', function () {
    $beneficiary = Beneficiary::factory()->dueForRetention()->create();
    $beneficiary->close();

    $beneficiary->deIdentify();

    expect(Beneficiary::withTrashed()->count())->toBe(0)
        ->and(BeneficiaryImpactRecord::count())->toBe(1);
});

it('severs the link to the beneficiary when the record is destroyed', function () {
    // Pseudonymous while the identifiable record lawfully exists; genuinely
    // unlinked once it is gone. Not a hash, not an encrypted id — nothing.
    $beneficiary = Beneficiary::factory()->dueForRetention()->create();
    $record = $beneficiary->close();

    expect($record->isStillLinked())->toBeTrue();

    $beneficiary->deIdentify();

    expect($record->fresh()->isStillLinked())->toBeFalse()
        ->and($record->fresh()->source_beneficiary_id)->toBeNull();
});

it('hard deletes rather than soft deleting', function () {
    // A soft-deleted row still holds every identifier and would satisfy
    // Act 843 not at all.
    $beneficiary = Beneficiary::factory()->dueForRetention()->create();

    $beneficiary->deIdentify();

    expect(Beneficiary::withTrashed()->find($beneficiary->id))->toBeNull();
});

it('projects a record even for a case that never had one', function () {
    // A case closed before the projection existed, or one whose projection
    // failed, must not lose its statistics on the way out.
    $beneficiary = Beneficiary::factory()->dueForRetention()->create();

    expect(BeneficiaryImpactRecord::count())->toBe(0);

    $beneficiary->deIdentify();

    expect(BeneficiaryImpactRecord::count())->toBe(1);
});

it('gives a sensitive document a far shorter life than the case it supports', function () {
    // 24 months against 72. A medical report proving eligibility has served
    // its purpose once the case closes.
    $beneficiary = Beneficiary::factory()->closed()->create();

    $medical = BeneficiaryDocument::create([
        'beneficiary_id' => $beneficiary->id,
        'title' => 'Hospital referral',
        'document_type' => BeneficiaryDocument::TYPE_MEDICAL,
    ]);

    $other = BeneficiaryDocument::create([
        'beneficiary_id' => $beneficiary->id,
        'title' => 'Signed acceptance',
        'document_type' => BeneficiaryDocument::TYPE_OTHER,
    ]);

    expect($medical->retentionClass())->toBe('beneficiary_sensitive_document')
        ->and($other->retentionClass())->toBe('beneficiary_case_record');
});

it('treats a medical document as sensitive whatever the flag says', function () {
    // The shorter, safer period wins by default rather than depending on
    // somebody ticking a box.
    $document = BeneficiaryDocument::create([
        'beneficiary_id' => Beneficiary::factory()->create()->id,
        'title' => 'Diagnosis summary',
        'document_type' => BeneficiaryDocument::TYPE_MEDICAL,
        'is_sensitive' => false,
    ]);

    expect($document->fresh()->is_sensitive)->toBeTrue();
});

it('closes a case documents along with the case, starting their clock', function () {
    $beneficiary = Beneficiary::factory()->create();
    $document = BeneficiaryDocument::create([
        'beneficiary_id' => $beneficiary->id,
        'title' => 'Referral',
        'document_type' => BeneficiaryDocument::TYPE_REFERRAL,
    ]);

    expect($document->retentionAnchorDate())->toBeNull();

    $beneficiary->close();

    expect($document->fresh()->retentionAnchorDate())->not->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════════
//  The retention runner, end to end
// ═══════════════════════════════════════════════════════════════════════════

it('sweeps a case past its six-year period and logs it', function () {
    $beneficiary = Beneficiary::factory()->dueForRetention()->create();
    $beneficiary->close();

    $summary = app(RetentionRunner::class)->run(execute: true);

    expect($summary['acted'])->toBeGreaterThan(0)
        ->and(Beneficiary::withTrashed()->count())->toBe(0)
        ->and(RetentionLogEntry::where('action', 'de_identify')->count())->toBeGreaterThan(0)
        // The statistics survive.
        ->and(BeneficiaryImpactRecord::count())->toBe(1);
});

it('leaves a recently closed case alone', function () {
    Beneficiary::factory()->closed()->create()->close();

    app(RetentionRunner::class)->run(execute: true);

    expect(Beneficiary::count())->toBe(1);
});

it('destroys nothing on a dry run', function () {
    $beneficiary = Beneficiary::factory()->dueForRetention()->create();
    $beneficiary->close();

    $summary = app(RetentionRunner::class)->run();

    expect($summary['dry_run'])->toBeTrue()
        ->and(Beneficiary::count())->toBe(1);
});

it('stops for a legal hold', function () {
    $beneficiary = Beneficiary::factory()->dueForRetention()->create();
    $beneficiary->close();

    LegalHold::create([
        'title' => 'Audit of the 2020 education programme',
        'reason' => 'External audit requested the underlying case files.',
        'retention_class' => 'beneficiary_case_record',
        'placed_on' => now()->toDateString(),
    ]);

    $summary = app(RetentionRunner::class)->run(execute: true);

    expect($summary['held'])->toBeGreaterThan(0)
        ->and(Beneficiary::count())->toBe(1);
});

it('writes the audit digest before the record is destroyed', function () {
    $beneficiary = Beneficiary::factory()->dueForRetention()->create();
    $beneficiary->close();
    $digest = $beneficiary->retentionDigest();

    app(RetentionRunner::class)->run(execute: true);

    // Lets a specific person be matched against the log on request, without
    // the log holding their details.
    expect(RetentionLogEntry::where('subject_digest', $digest)->exists())->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Consent
// ═══════════════════════════════════════════════════════════════════════════

it('treats an absent consent record as no permission', function () {
    // The most common way permission ends up assumed.
    $story = Story::factory()->create();

    expect($story->hasConsentFor(Consent::TYPE_STORY))->toBeFalse()
        ->and($story->hasAllRequiredConsents())->toBeFalse();
});

it('refuses to publish a story without story consent', function () {
    $story = Story::factory()->create();

    $story->publish();
})->throws(RuntimeException::class, 'cannot be published without consent');

it('publishes once consent is on file', function () {
    $story = Story::factory()->create();
    $story->consents()->create(Consent::factory()->raw());

    $story->load('consents')->publish();

    expect($story->fresh()->is_published)->toBeTrue();
});

it('demands a photo consent as well once there is a photograph', function () {
    $story = Story::factory()->create();
    $story->consents()->create(Consent::factory()->raw());
    $story->image_id = null;

    // Without an image: story consent is enough.
    expect($story->load('consents')->missingConsents())->toBe([]);

    $story->setAttribute('image_id', 1);

    expect($story->missingConsents())->toBe([Consent::TYPE_PHOTO]);
});

it('demands a name-use consent to publish a real name', function () {
    $story = Story::factory()->withRealName()->create();
    $story->consents()->create(Consent::factory()->raw());

    expect($story->load('consents')->missingConsents())->toBe([Consent::TYPE_NAME_USE]);
});

it('needs no name-use consent for a pseudonym', function () {
    // Which is the entire point of using one.
    $story = Story::factory()->withPseudonym()->create();
    $story->consents()->create(Consent::factory()->raw());

    expect($story->load('consents')->missingConsents())->toBe([]);
});

it('says which consent is missing, not merely that one is', function () {
    // "Cannot publish" with no explanation is how a consent requirement gets
    // worked around instead of satisfied.
    $story = Story::factory()->withRealName()->create();

    expect($story->missingConsents())->toBe([Consent::TYPE_STORY, Consent::TYPE_NAME_USE]);
});

it('accepts the beneficiary consent signed once at intake', function () {
    $beneficiary = Beneficiary::factory()->create();
    $beneficiary->consents()->create(Consent::factory()->raw());

    $story = Story::factory()->create(['beneficiary_id' => $beneficiary->id]);

    expect($story->hasConsentFor(Consent::TYPE_STORY))->toBeTrue();
});

it('stops treating an expired consent as permission', function () {
    $story = Story::factory()->create();
    $story->consents()->create(Consent::factory()->expired()->raw());

    expect($story->load('consents')->hasConsentFor(Consent::TYPE_STORY))->toBeFalse();
});

it('treats revocation as immediate, whatever the expiry date says', function () {
    // Somebody withdrawing consent is an instruction, not a suggestion.
    $story = Story::factory()->create();
    $consent = $story->consents()->create(Consent::factory()->raw(['expires_at' => now()->addYears(5)]));

    $consent->revoke('Withdrawn by the beneficiary.');

    expect($story->load('consents')->hasConsentFor(Consent::TYPE_STORY))->toBeFalse()
        ->and($story->isLive())->toBeFalse();
});

it('lets a story be taken down when consent is revoked', function () {
    $story = Story::factory()->create();
    $consent = $story->consents()->create(Consent::factory()->raw());
    $story->load('consents')->publish();

    $consent->revoke('Withdrawn.');
    $story->load('consents')->unpublish('Consent withdrawn.');

    expect($story->fresh()->is_published)->toBeFalse();
});

it('does not honour a consent granted for print when publishing to the website', function () {
    $story = Story::factory()->create();
    $story->consents()->create(Consent::factory()->raw(['scope' => Consent::SCOPE_PRINT]));

    expect($story->load('consents')->hasConsentFor(Consent::TYPE_STORY, Consent::SCOPE_WEBSITE))->toBeFalse();
});

it('honours a consent granted for all channels', function () {
    $story = Story::factory()->create();
    $story->consents()->create(Consent::factory()->raw(['scope' => Consent::SCOPE_ALL]));

    expect($story->load('consents')->hasConsentFor(Consent::TYPE_STORY, Consent::SCOPE_SOCIAL))->toBeTrue();
});

it('refuses to record a minor consent with no guardian named', function () {
    // Publishing a photograph of a child on an unattributed consent is the
    // single riskiest thing this system can do.
    Consent::factory()->create([
        'consentable_type' => Story::class,
        'consentable_id' => Story::factory()->create()->id,
        'is_minor' => true,
        'guardian_name' => null,
        'granted_by_relationship' => Consent::BY_GUARDIAN,
    ]);
})->throws(RuntimeException::class, 'must name the parent or guardian');

it('refuses to let a minor consent on their own behalf', function () {
    Consent::factory()->create([
        'consentable_type' => Story::class,
        'consentable_id' => Story::factory()->create()->id,
        'is_minor' => true,
        'guardian_name' => 'Ama Boateng',
        'granted_by_relationship' => Consent::BY_SELF,
    ]);
})->throws(RuntimeException::class, 'cannot give consent on their own behalf');

it('withholds a real name the moment name-use consent is revoked', function () {
    $story = Story::factory()->withRealName('Ama Serwaa Boateng')->create();
    $story->consents()->create(Consent::factory()->raw());
    $nameUse = $story->consents()->create(Consent::factory()->ofType(Consent::TYPE_NAME_USE)->raw());

    expect($story->load('consents')->displayName())->toBe('Ama Serwaa Boateng');

    $nameUse->revoke();

    expect($story->load('consents')->displayName())->toBeNull();
});

it('keeps a published story when the case record is destroyed', function () {
    // By then the story holds only what consent covered.
    $beneficiary = Beneficiary::factory()->dueForRetention()->create();
    $beneficiary->consents()->create(Consent::factory()->raw());
    $story = Story::factory()->create(['beneficiary_id' => $beneficiary->id]);
    $story->publish();

    $beneficiary->deIdentify();

    expect($story->fresh())->not->toBeNull()
        ->and($story->fresh()->beneficiary_id)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Disclosure control on the analytics dataset
// ═══════════════════════════════════════════════════════════════════════════

it('suppresses a breakdown cell covering fewer than five people', function () {
    $project = Project::factory()->create();

    // Two beneficiaries in one district and period. Anonymous individually;
    // the cell is not.
    BeneficiaryImpactRecord::factory()->count(2)->create([
        'project_id' => $project->id,
        'district' => 'Tamale Metropolitan',
        'assistance_period' => '2026-03',
    ]);

    BeneficiaryImpactRecord::factory()->count(9)->create([
        'project_id' => $project->id,
        'district' => 'Kumasi Metropolitan',
        'assistance_period' => '2026-03',
    ]);

    $rows = BeneficiaryImpactRecord::breakdown(['district']);

    $tamale = $rows->firstWhere('district', 'Tamale Metropolitan');
    $kumasi = $rows->firstWhere('district', 'Kumasi Metropolitan');

    expect($tamale['count'])->toBeNull()
        ->and($tamale['suppressed'])->toBeTrue()
        ->and($kumasi['count'])->toBe(9)
        ->and($kumasi['suppressed'])->toBeFalse();
});
