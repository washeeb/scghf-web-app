<?php

declare(strict_types=1);

use App\Models\SafeguardingCheck;
use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerApplication;
use App\Models\VolunteerHour;
use App\Models\VolunteerOpportunity;
use App\Support\RetentionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Volunteers and safeguarding
|--------------------------------------------------------------------------
|
| Two of this foundation's four divisions exist to work with orphans,
| vulnerable children, widows and the elderly. That makes safeguarding a
| structural property of the software rather than a policy filed somewhere.
|
| The failure these tests exist to prevent is not malice. It is a well-meaning
| administrator approving a keen volunteer "and doing the police check next
| week" — which is the ordinary failure, and the one every inquiry describes.
|
*/

beforeEach(function () {
    $this->assessor = User::factory()->staff()->create();
    $this->supervisor = User::factory()->staff()->create();
});

/** An application for a role involving contact with vulnerable people. */
function applicationForVulnerableRole(): VolunteerApplication
{
    $application = VolunteerApplication::factory()->create([
        'volunteer_opportunity_id' => VolunteerOpportunity::factory()->create()->id,
    ]);

    $application->submit();

    return $application->fresh()->load('checks', 'opportunity');
}

/** Pass every required check, the way an assessor working properly would. */
function passAllChecks(VolunteerApplication $application, User $assessor): void
{
    foreach ($application->checks as $check) {
        $check->pass($assessor, 'REF-'.strtoupper($check->check_type));
    }

    $application->load('checks');
}

// ═══════════════════════════════════════════════════════════════════════════
//  The gate
// ═══════════════════════════════════════════════════════════════════════════

it('refuses to approve a volunteer while any check is outstanding', function () {
    // The ordinary failure: approving somebody keen and doing the check later.
    applicationForVulnerableRole()->approve(User::factory()->staff()->create());
})->throws(RuntimeException::class, 'Outstanding safeguarding checks');

it('names exactly which checks are missing', function () {
    // "Cannot approve" with no explanation is how a requirement gets worked
    // around instead of met.
    $application = applicationForVulnerableRole();
    $application->checks->firstWhere('check_type', 'declaration')
        ->pass($this->assessor, 'Signed 3 September');

    $outstanding = $application->fresh()->load('checks')->outstandingChecks();

    expect($outstanding)->toContain('police_clearance')
        ->toContain('reference_one')
        ->toContain('reference_two')
        ->toContain('interview')
        ->not->toContain('declaration');
});

it('approves once every check is recorded', function () {
    $application = applicationForVulnerableRole();
    passAllChecks($application, $this->assessor);

    $volunteer = $application->fresh()->approve($this->assessor, 'Reading helper');

    expect($volunteer->status)->toBe(Volunteer::STATUS_ACTIVE)
        ->and($volunteer->is_cleared)->toBeTrue()
        ->and($volunteer->isCurrentlyCleared())->toBeTrue()
        ->and($application->fresh()->status)->toBe(VolunteerApplication::STATUS_APPROVED);
});

it('refuses to approve when a check was recorded as failed', function () {
    // A failed check has to be revisited explicitly, not overridden at approval.
    $application = applicationForVulnerableRole();
    passAllChecks($application, $this->assessor);
    $application->checks->firstWhere('check_type', 'police_clearance')
        ->fail($this->assessor, 'Undisclosed conviction found.');

    $application->fresh()->load('checks')->approve($this->assessor);
})->throws(RuntimeException::class, 'recorded as FAILED');

it('requires fewer checks for a role with no vulnerable contact', function () {
    // Requiring a police check to hand out leaflets means the foundation either
    // never recruits anybody or starts treating the requirement as a formality
    // — and a formality is not a safeguard.
    $opportunity = VolunteerOpportunity::factory()->withoutVulnerableContact()->create();
    $application = VolunteerApplication::factory()->create([
        'volunteer_opportunity_id' => $opportunity->id,
    ]);
    $application->submit();
    $application = $application->fresh()->load('checks', 'opportunity');

    expect($application->requiredChecks())->toBe(['declaration']);

    $application->checks->first()->pass($this->assessor, 'Signed 3 September');

    expect($application->fresh()->load('checks')->approve($this->assessor))
        ->toBeInstanceOf(Volunteer::class);
});

it('assumes vulnerable contact when nobody has said otherwise', function () {
    // The safe default for a foundation whose work is orphans and widows. A
    // default of false would mean every role somebody forgot to configure
    // skipped its checks.
    expect(VolunteerOpportunity::factory()->create()->involves_vulnerable_contact)->toBeTrue();

    $orphaned = VolunteerApplication::factory()->create(['volunteer_opportunity_id' => null]);

    expect($orphaned->requiredChecks())->toContain('police_clearance');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Evidence
// ═══════════════════════════════════════════════════════════════════════════

it('refuses to record a pass with no reference', function () {
    // A check with no evidence is a claim.
    $application = applicationForVulnerableRole();

    $application->checks->first()->update([
        'outcome' => SafeguardingCheck::OUTCOME_PASSED,
        'verified_by' => $this->assessor->id,
    ]);
})->throws(RuntimeException::class, 'without a reference');

it('refuses to record a pass with nobody named as verifier', function () {
    // A check nobody put their name to is not a check.
    $application = applicationForVulnerableRole();

    $application->checks->first()->update([
        'outcome' => SafeguardingCheck::OUTCOME_PASSED,
        'reference' => 'REF-1',
    ]);
})->throws(RuntimeException::class, 'who verified it');

it('refuses to waive a check without a reason and an authoriser', function () {
    // Waiving a police check on somebody who will work unsupervised with
    // children is a decision that has to be owned.
    $application = applicationForVulnerableRole();

    $application->checks->first()->update(['outcome' => SafeguardingCheck::OUTCOME_WAIVED]);
})->throws(RuntimeException::class, 'has to be owned');

it('accepts a waiver that is properly authorised', function () {
    $application = applicationForVulnerableRole();
    $check = $application->checks->firstWhere('check_type', 'reference_two');

    $check->waive($this->supervisor, 'Second referee unreachable; interview extended instead.');

    expect($check->fresh()->isSatisfied())->toBeTrue()
        ->and($check->fresh()->waived_by)->toBe($this->supervisor->id);
});

it('records one row per check, with its own evidence', function () {
    // The question an inquiry asks is not "was this person cleared" but "what
    // did you check, when, on what evidence, and who signed it off".
    $application = applicationForVulnerableRole();

    $expected = array_keys(config('compliance.safeguarding.required_checks'));
    sort($expected);

    expect($application->checks)->toHaveCount(5)
        ->and($application->checks->pluck('check_type')->sort()->values()->all())->toBe($expected);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Clearances go stale
// ═══════════════════════════════════════════════════════════════════════════

it('gives a police clearance an expiry by default', function () {
    // A certificate is a statement about a point in time, not a permanent
    // property of a person.
    $application = applicationForVulnerableRole();
    $check = $application->checks->firstWhere('check_type', 'police_clearance');

    $check->pass($this->assessor, 'GPS/CRC/2026/8817');

    expect($check->fresh()->expires_on)->not->toBeNull()
        ->and($check->fresh()->expires_on->toDateString())
        ->toBe(now()->addMonths(24)->toDateString());
});

it('stops treating an expired clearance as satisfying anything', function () {
    $application = applicationForVulnerableRole();
    passAllChecks($application, $this->assessor);
    $volunteer = $application->fresh()->approve($this->assessor);

    // The clearance lapses.
    $application->fresh()->checks->firstWhere('check_type', 'police_clearance')
        ->forceFill(['expires_on' => now()->subDay()->toDateString()])->save();

    // Evaluated from the dates, not from the cached flag — so it stops counting
    // the day it expires, not the next time somebody runs a report.
    expect($volunteer->fresh()->isCurrentlyCleared())->toBeFalse()
        ->and($volunteer->fresh()->isAvailable())->toBeFalse();
});

it('refreshes the cached clearance flag from the checks behind it', function () {
    $application = applicationForVulnerableRole();
    passAllChecks($application, $this->assessor);
    $volunteer = $application->fresh()->approve($this->assessor);

    $application->fresh()->checks->firstWhere('check_type', 'police_clearance')
        ->forceFill(['expires_on' => now()->subDay()->toDateString()])->save();

    expect($volunteer->fresh()->refreshClearance())->toBeFalse()
        ->and($volunteer->fresh()->is_cleared)->toBeFalse();
});

it('lists volunteers whose clearance is about to lapse', function () {
    $application = applicationForVulnerableRole();
    passAllChecks($application, $this->assessor);
    $volunteer = $application->fresh()->approve($this->assessor);

    $volunteer->forceFill(['clearance_expires_on' => now()->addDays(20)->toDateString()])->save();

    expect(Volunteer::query()->clearanceLapsing(60)->pluck('id')->all())->toBe([$volunteer->id]);
});

it('treats a volunteer with no application as uncleared, not unknown', function () {
    $volunteer = Volunteer::create([
        'full_name' => 'Kofi Mensah',
        'started_on' => now()->toDateString(),
    ]);

    expect($volunteer->isCurrentlyCleared())->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Concerns
// ═══════════════════════════════════════════════════════════════════════════

it('suspends a volunteer the moment a concern is raised', function () {
    /*
     * Before any investigation, and without implying a finding. Reversing that
     * order — however reasonable it feels in the moment — is the failure every
     * safeguarding inquiry describes.
     */
    $application = applicationForVulnerableRole();
    passAllChecks($application, $this->assessor);
    $volunteer = $application->fresh()->approve($this->assessor);

    $volunteer->raiseConcern($this->supervisor, 'A parent reported an unaccompanied car journey.');

    expect($volunteer->fresh()->status)->toBe(Volunteer::STATUS_SUSPENDED)
        ->and($volunteer->fresh()->hasOpenConcern())->toBeTrue()
        ->and($volunteer->fresh()->isAvailable())->toBeFalse();
});

it('insists a concern is written down', function () {
    // What was noticed, and when, is the only thing anybody will have to work
    // from later.
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->toDateString()]);

    $volunteer->raiseConcern($this->supervisor, '');
})->throws(RuntimeException::class, 'needs to be written down');

it('insists on a written outcome before reinstating', function () {
    // A concern that simply stops being mentioned is the worst possible record
    // of one.
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->toDateString()]);
    $volunteer->raiseConcern($this->supervisor, 'Reported by a parent.');

    $volunteer->resolveConcern($this->supervisor, '');
})->throws(RuntimeException::class, 'written outcome');

it('keeps the concern history when it is resolved', function () {
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->toDateString()]);
    $volunteer->raiseConcern($this->supervisor, 'Reported by a parent.');

    $volunteer->resolveConcern($this->supervisor, 'Investigated; the journey was authorised.');

    expect($volunteer->fresh()->status)->toBe(Volunteer::STATUS_ACTIVE)
        ->and($volunteer->fresh()->hasOpenConcern())->toBeFalse()
        // Both the concern and its outcome survive.
        ->and($volunteer->fresh()->concern_note)->toContain('Reported by a parent')
        ->and($volunteer->fresh()->concern_note)->toContain('the journey was authorised');
});

it('lists volunteers with an open concern', function () {
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->toDateString()]);
    $volunteer->raiseConcern($this->supervisor, 'Reported.');
    Volunteer::create(['full_name' => 'Ama', 'started_on' => now()->toDateString()]);

    expect(Volunteer::query()->withOpenConcern()->pluck('id')->all())->toBe([$volunteer->id]);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Applications
// ═══════════════════════════════════════════════════════════════════════════

it('refuses to submit without the safeguarding declaration', function () {
    // It is what the applicant is later held to.
    VolunteerApplication::factory()->withoutDeclaration()->create()->submit();
})->throws(RuntimeException::class, 'declaration must be agreed');

it('keeps the declaration text, not merely that a box was ticked', function () {
    // "They ticked a box" is not evidence of what they were asked. The text
    // they agreed to is.
    $application = applicationForVulnerableRole();

    expect($application->declaration_text)->not->toBeEmpty()
        ->and($application->declaration_at)->not->toBeNull();
});

it('opens the required check rows on submission', function () {
    $application = applicationForVulnerableRole();

    expect($application->checks->every(
        fn (SafeguardingCheck $c): bool => $c->outcome === SafeguardingCheck::OUTCOME_PENDING,
    ))->toBeTrue();
});

it('fills a position when an application is approved', function () {
    $opportunity = VolunteerOpportunity::factory()->create(['positions_available' => 2]);
    $application = VolunteerApplication::factory()->create([
        'volunteer_opportunity_id' => $opportunity->id,
    ]);
    $application->submit();
    passAllChecks($application->fresh()->load('checks'), $this->assessor);

    $application->fresh()->approve($this->assessor);

    expect($opportunity->fresh()->positions_filled)->toBe(1)
        ->and($opportunity->fresh()->remainingPositions())->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Hours
// ═══════════════════════════════════════════════════════════════════════════

it('stores time in minutes, never as a fractional hour', function () {
    // "2.5 hours" as a float is how a total reported to a funder ends up at
    // 3,399.9999999.
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->toDateString()]);

    $entry = VolunteerHour::create([
        'volunteer_id' => $volunteer->id,
        'worked_on' => now()->subDay()->toDateString(),
        'minutes' => 150,
    ]);

    expect($entry->minutes)->toBe(150)
        ->and($entry->hours())->toBe(2.5);
});

it('counts only verified hours towards the reported total', function () {
    // A figure a funder is shown must have rows behind it that somebody checked.
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->toDateString()]);
    $recorder = User::factory()->staff()->create();

    $verified = VolunteerHour::create([
        'volunteer_id' => $volunteer->id, 'worked_on' => now()->subDay(),
        'minutes' => 120, 'recorded_by' => $recorder->id,
    ]);
    VolunteerHour::create([
        'volunteer_id' => $volunteer->id, 'worked_on' => now()->subDay(),
        'minutes' => 300, 'recorded_by' => $recorder->id,
    ]);

    $verified->verify($this->supervisor);

    expect($volunteer->fresh()->total_hours)->toBe(2);
});

it('will not let somebody verify their own hours', function () {
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->toDateString()]);

    $entry = VolunteerHour::create([
        'volunteer_id' => $volunteer->id, 'worked_on' => now()->subDay(),
        'minutes' => 120, 'recorded_by' => $this->supervisor->id,
    ]);

    $entry->verify($this->supervisor);
})->throws(RuntimeException::class, 'cannot be verified by the person who recorded them');

it('rejects an entry longer than a day', function () {
    // Most often a start and end time entered as minutes, and it inflates a
    // figure the foundation reports to a funder.
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->toDateString()]);

    VolunteerHour::create([
        'volunteer_id' => $volunteer->id, 'worked_on' => now()->subDay(), 'minutes' => 2_000,
    ]);
})->throws(RuntimeException::class, 'cannot exceed 24 hours');

it('rejects hours recorded for a day that has not happened', function () {
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->toDateString()]);

    VolunteerHour::create([
        'volunteer_id' => $volunteer->id, 'worked_on' => now()->addWeek(), 'minutes' => 60,
    ]);
})->throws(RuntimeException::class, 'has not happened');

// ═══════════════════════════════════════════════════════════════════════════
//  Retention
// ═══════════════════════════════════════════════════════════════════════════

it('classifies every column on the application table', function () {
    expect(VolunteerApplication::unclassifiedColumns())->toBe([]);
});

it('classifies every column on the volunteer table', function () {
    expect(Volunteer::unclassifiedColumns())->toBe([]);
});

it('gives a serving volunteer no anchor date, so they are never swept up', function () {
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->subYears(3)]);

    expect($volunteer->retentionAnchorDate())->toBeNull();
});

it('starts the clock when a volunteer leaves', function () {
    $volunteer = Volunteer::create(['full_name' => 'Kofi', 'started_on' => now()->subYears(3)]);

    $volunteer->leave('Moved abroad.');

    expect($volunteer->fresh()->retentionAnchorDate())->not->toBeNull()
        ->and($volunteer->fresh()->retentionClass())->toBe('volunteer_record');
});

it('sweeps a declined application after its shorter period', function () {
    // Twelve months, not the six years a volunteer record gets: there was no
    // placement, so there is nothing to be accountable for beyond the decision.
    $application = VolunteerApplication::factory()->declined()->create();

    $summary = app(RetentionRunner::class)->run(execute: true);

    expect($summary['acted'])->toBeGreaterThan(0)
        ->and(VolunteerApplication::withTrashed()->find($application->id))->toBeNull();
});

it('leaves a recently declined application alone', function () {
    VolunteerApplication::factory()->create([
        'status' => VolunteerApplication::STATUS_DECLINED,
        'decided_at' => now()->subMonth(),
    ]);

    app(RetentionRunner::class)->run(execute: true);

    expect(VolunteerApplication::count())->toBe(1);
});

it('keeps a safeguarding record far longer than a declined application', function () {
    // A concern raised after a volunteer has left must be answerable against
    // what the foundation knew and checked at the time.
    expect(config('compliance.retention.classes.volunteer_record.months'))->toBe(72)
        ->and(config('compliance.retention.classes.volunteer_application_declined.months'))->toBe(12)
        ->and(config('compliance.retention.classes.volunteer_record.sensitive'))->toBeTrue();
});
