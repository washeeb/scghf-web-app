<?php

declare(strict_types=1);

use App\Enums\CauseStatus;
use App\Models\Cause;
use App\Models\CauseUpdate;
use App\Models\Division;
use App\Models\ImpactMetric;
use App\Models\ImpactMetricValue;
use App\Models\Media;
use App\Models\TaxApproval;
use App\Models\User;
use App\Support\DisclosureControl;
use App\ValueObjects\Money;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function causeApproval(array $overrides = []): TaxApproval
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

// ═══════════════════════════════════════════════════════════════════════════
//  The General Fund — every donation needs a destination
// ═══════════════════════════════════════════════════════════════════════════

it('seeds a General Fund so a gift with no chosen appeal still has somewhere to go', function () {
    $this->seed([DivisionSeeder::class, CauseSeeder::class]);

    $fund = Cause::generalFund();

    expect($fund->slug)->toBe('general-fund')
        ->and($fund->is_locked)->toBeTrue()
        ->and($fund->acceptsDonations())->toBeTrue()
        // No target: a goal of zero renders as a progress bar stuck at 100%,
        // and the General Fund is never "complete".
        ->and($fund->goal)->toBeNull();
});

it('refuses to delete the General Fund', function () {
    $this->seed([DivisionSeeder::class, CauseSeeder::class]);

    Cause::generalFund()->delete();
})->throws(RuntimeException::class, 'cannot be deleted');

it('fails loudly rather than silently when no General Fund exists', function () {
    // A donation reaching this point and finding no destination is a payment
    // the foundation has taken and cannot account for.
    Cause::generalFund();
})->throws(RuntimeException::class, 'No General Fund cause exists');

it('seeds division funds as drafts rather than inventing appeal copy', function () {
    $this->seed([DivisionSeeder::class, CauseSeeder::class]);

    $cause = Cause::where('slug', 'life-spring-fund')->first();

    expect($cause->status)->toBe(CauseStatus::Draft)
        ->and($cause->is_published)->toBeFalse()
        ->and($cause->summary)->toBeNull();
});

it('seeds causes idempotently', function () {
    $this->seed([DivisionSeeder::class, CauseSeeder::class]);
    $this->seed(CauseSeeder::class);

    expect(Cause::count())->toBe(5);   // General Fund plus one per division
});

// ═══════════════════════════════════════════════════════════════════════════
//  Money and progress
// ═══════════════════════════════════════════════════════════════════════════

it('holds a goal and a raised total as integer pesewas', function () {
    $cause = Cause::factory()->create(['goal' => 5_000_000]);
    $cause->forceFill(['raised_minor' => 1_250_000])->save();

    expect($cause->fresh()->goal)->toEqualPesewas(5_000_000)
        ->and($cause->fresh()->raisedAmount())->toEqualPesewas(1_250_000)
        ->and($cause->fresh()->progressPercent())->toBe(25);
});

it('reports over-target rather than clamping at a hundred', function () {
    // An appeal that raised 140% of its goal should say so — clamping hides
    // the best news the page has.
    $cause = Cause::factory()->create(['goal' => 1_000_000]);
    $cause->forceFill(['raised_minor' => 1_400_000])->save();

    expect($cause->fresh()->progressPercent())->toBe(140)
        ->and($cause->fresh()->remaining())->toEqualPesewas(0);
});

it('has no progress figure without a target', function () {
    $cause = Cause::factory()->create(['goal' => null]);

    expect($cause->progressPercent())->toBeNull()
        ->and($cause->remaining())->toBeNull();
});

it('reports a zero raised total as Money, never as null', function () {
    $cause = Cause::factory()->create();

    expect($cause->raisedAmount())->toEqualPesewas(0);
});

it('refuses a float goal rather than storing it as pesewas', function () {
    Cause::factory()->create(['goal' => 50000.00]);
})->throws(InvalidArgumentException::class);

// ═══════════════════════════════════════════════════════════════════════════
//  Accepting donations
// ═══════════════════════════════════════════════════════════════════════════

it('stops accepting donations after the closing date', function () {
    // An appeal past its end date still taking money is how a foundation ends
    // up holding funds it announced it had stopped raising.
    $cause = Cause::factory()->closed()->create();

    expect($cause->acceptsDonations())->toBeFalse()
        ->and(Cause::query()->accepting()->pluck('id')->all())->toBe([]);
});

it('does not accept donations before the appeal opens', function () {
    $cause = Cause::factory()->create(['starts_on' => now()->addWeek()->toDateString()]);

    expect($cause->acceptsDonations())->toBeFalse();
});

it('does not accept donations while paused', function () {
    $cause = Cause::factory()->create(['status' => CauseStatus::Paused]);

    expect($cause->acceptsDonations())->toBeFalse()
        // ...but stays visible, because the appeal and its total are still
        // part of the record.
        ->and($cause->isLive())->toBeTrue();
});

it('keeps a completed appeal visible as evidence the money did something', function () {
    $cause = Cause::factory()->create(['status' => CauseStatus::Completed]);

    expect($cause->isLive())->toBeTrue()
        ->and($cause->acceptsDonations())->toBeFalse();
});

it('hides an archived appeal entirely', function () {
    $cause = Cause::factory()->create(['status' => CauseStatus::Archived]);

    expect($cause->isLive())->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Tax deductibility — the flag is necessary, not sufficient
// ═══════════════════════════════════════════════════════════════════════════

it('claims nothing for a flagged cause while no GRA approval is held', function () {
    // The correction at the heart of the compliance layer: being flagged as a
    // worthwhile cause does not make a donation deductible. The foundation must
    // also hold a current written approval.
    $cause = Cause::factory()->taxDeductible()->create();

    expect($cause->is_tax_deductible)->toBeTrue()
        ->and($cause->qualifiesForTaxRelief())->toBeFalse();
});

it('qualifies once an approval is held and the cause is flagged', function () {
    causeApproval();
    $cause = Cause::factory()->taxDeductible()->create();

    expect($cause->qualifiesForTaxRelief())->toBeTrue();
});

it('does not make every cause qualify just because the organisation is approved', function () {
    // A s.97 approval approves the ORGANISATION. It does not make every
    // activity it runs a worthwhile cause.
    causeApproval();
    $cause = Cause::factory()->create();   // not flagged

    expect($cause->qualifiesForTaxRelief())->toBeFalse();
});

it('qualifies a cause carrying its own section 100 approval', function () {
    causeApproval();   // the organisation-wide s.97
    $specific = causeApproval(['approval_type' => TaxApproval::TYPE_SECTION_100]);

    $cause = Cause::factory()->create(['tax_approval_id' => $specific->id]);

    expect($cause->qualifiesForTaxRelief())->toBeTrue();
});

it('stops qualifying the day the approval expires', function () {
    causeApproval(['issued_on' => now()->subYears(2), 'expires_on' => now()->subDay()]);
    $cause = Cause::factory()->taxDeductible()->create();

    expect($cause->qualifiesForTaxRelief())->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Impact metrics
// ═══════════════════════════════════════════════════════════════════════════

it('keeps figures as a time series rather than one running total', function () {
    $metric = ImpactMetric::factory()->create();

    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2025-01-01', 'value' => 120]);
    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-01-01', 'value' => 180]);

    // The whole point: this year is comparable with last year.
    expect($metric->total())->toBe(300.0)
        ->and($metric->total(from: '2026-01-01'))->toBe(180.0);
});

it('refuses two figures for the same metric and period', function () {
    $metric = ImpactMetric::factory()->create();

    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-01-01', 'value' => 1]);
    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-01-01', 'value' => 2]);
})->throws(QueryException::class);

it('combines periods according to the metric aggregation', function () {
    $metric = ImpactMetric::factory()->create(['aggregation' => ImpactMetric::AGGREGATION_LATEST]);

    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2025-01-01', 'value' => 120]);
    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-01-01', 'value' => 45]);

    // A headcount at a point in time does not sum across years.
    expect($metric->total())->toBe(45.0);
});

it('records who verified a figure and when', function () {
    // A published impact figure nobody checked is how a foundation ends up
    // defending a number it cannot support.
    $metric = ImpactMetric::factory()->create();
    $value = ImpactMetricValue::create([
        'impact_metric_id' => $metric->id, 'period_start' => '2026-01-01', 'value' => 90,
    ]);

    expect($value->isVerified())->toBeFalse();

    $value->verify(User::factory()->staff()->create());

    expect($value->fresh()->isVerified())->toBeTrue()
        ->and($value->fresh()->verified_by)->not->toBeNull();
});

it('labels a period at the privacy layer granularity, not the exact day', function () {
    $metric = ImpactMetric::factory()->create();
    $value = ImpactMetricValue::create([
        'impact_metric_id' => $metric->id, 'period_start' => '2026-03-13', 'value' => 4,
    ]);

    expect($value->periodLabel())->toBe('2026-03');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Disclosure control on published figures
// ═══════════════════════════════════════════════════════════════════════════

it('suppresses a people-counting figure below the minimum group size', function () {
    // "2 beneficiaries supported in Widower Support, Tamale, March 2026"
    // identifies those people as surely as printing their names would.
    $metric = ImpactMetric::factory()->countsPeople()->create();
    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-01-01', 'value' => 2]);

    expect($metric->total())->toBe(2.0)
        ->and($metric->publishedTotal())->toBeNull()
        ->and($metric->format($metric->publishedTotal()))->toBe(DisclosureControl::NOTICE);
});

it('publishes a people-counting figure at or above the threshold', function () {
    $metric = ImpactMetric::factory()->countsPeople()->create();
    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-01-01', 'value' => 40]);

    expect($metric->publishedTotal())->toBe(40.0);
});

it('never suppresses a figure that counts things rather than people', function () {
    // One borehole is one borehole.
    $metric = ImpactMetric::factory()->create(['unit' => 'boreholes']);
    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-01-01', 'value' => 1]);

    expect($metric->publishedTotal())->toBe(1.0)
        ->and($metric->format(1.0))->toBe('1 boreholes');
});

it('formats a money metric through the Money value object', function () {
    $metric = ImpactMetric::factory()->money()->create();

    expect($metric->format(473_500.0))->toBe('GH₵ 4,735.00');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Updates
// ═══════════════════════════════════════════════════════════════════════════

it('scopes a cause update slug to its cause', function () {
    $one = Cause::factory()->create();
    $two = Cause::factory()->create();

    CauseUpdate::create(['cause_id' => $one->id, 'title' => 'Halfway there', 'body' => 'x']);
    CauseUpdate::create(['cause_id' => $two->id, 'title' => 'Halfway there', 'body' => 'y']);

    expect(CauseUpdate::where('slug', 'halfway-there')->count())->toBe(2);
});

it('scopes a cause to its division alongside foundation-wide appeals', function () {
    $division = Division::factory()->create();
    $scoped = Cause::factory()->create(['division_id' => $division->id]);
    $shared = Cause::factory()->create(['division_id' => null]);

    $listed = Cause::query()->forDivision($division)->pluck('id');

    expect($listed)->toContain($scoped->id)->toContain($shared->id);
});
