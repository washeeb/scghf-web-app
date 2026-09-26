<?php

declare(strict_types=1);

use App\Donors\ImpactTimeline;
use App\Enums\DonationStatus;
use App\Models\Cause;
use App\Models\CauseUpdate;
use App\Models\Donation;
use App\Models\DonationItem;
use App\Models\DonationReceipt;
use App\Models\Donor;
use App\Models\ImpactMetric;
use App\Models\ImpactMetricValue;
use App\Models\Project;
use App\Models\User;
use App\Payments\ReceiptIssuer;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Wave 1 — the donor portal: the story and the paperwork
|--------------------------------------------------------------------------
|
| /account/impact — every completed gift, the updates published on that
| work afterwards, and the public figures since the first gift.
| /account/receipts — receipts filed by tax year with the year's totals,
| each opening as the PDF, including gifts made before the account existed.
*/

beforeEach(function () {
    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');
    Storage::fake('local');

    $this->user = User::factory()->donor()->create(['email' => 'ama@example.com']);
    $this->donor = Donor::factory()->create(['email' => 'ama@example.com', 'user_id' => $this->user->id]);
});

/** A completed gift by this donor, paid on a date, with its item (so a receipt can be issued). */
function portalGift(Donor $donor, Cause $cause, string $major, string $paidOn): Donation
{
    $donation = Donation::factory()->create([
        'donor_id' => $donor->getKey(),
        'user_id' => null, // as from a phone, before the account existed
        'cause_id' => $cause->getKey(),
        'amount' => Money::ofMajor($major),
        'donor_name' => 'Ama Mensah',
        'donor_email' => $donor->email,
    ]);

    DonationItem::create([
        'donation_id' => $donation->id,
        'cause_id' => $cause->id,
        'amount' => $donation->amount_minor,
        ...DonationItem::snapshotDeductibility($cause),
    ]);

    $donation->recalculateDeductible();
    $donation->forceFill(['status' => DonationStatus::Completed, 'paid_at' => $paidOn])->save();

    return $donation->fresh();
}

// ── The impact timeline ─────────────────────────────────────────────────────

it('tells the story newest first: figures, then updates after the gift, then the gift', function () {
    $project = Project::factory()->create(['title' => 'Clean water for Nsawam']);
    $cause = Cause::factory()->create(['title' => 'A borehole for Nsawam', 'project_id' => $project->id]);

    portalGift($this->donor, $cause, '200.00', '2026-03-10 10:00:00');

    CauseUpdate::create(['cause_id' => $cause->id, 'title' => 'Before you gave', 'body' => 'x', 'is_published' => true, 'published_at' => '2026-02-01']);
    CauseUpdate::create(['cause_id' => $cause->id, 'title' => 'Drilling has started', 'body' => 'y', 'is_published' => true, 'published_at' => '2026-04-01']);
    CauseUpdate::create(['cause_id' => $cause->id, 'title' => 'Not yet published', 'body' => 'z', 'is_published' => false, 'published_at' => '2026-04-02']);

    $metric = ImpactMetric::factory()->create(['project_id' => $project->id, 'name' => 'Boreholes drilled', 'unit' => 'boreholes']);
    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-01-01', 'value' => 1]); // before the gift
    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-05-01', 'value' => 2]);

    $entries = app(ImpactTimeline::class)->for($this->donor);

    expect($entries->pluck('kind')->all())->toBe(['figures', 'update', 'gift'])
        ->and($entries[0]['figures'])->toBe([['label' => 'Boreholes drilled', 'value' => '2 boreholes']])
        ->and($entries[0]['title'])->toContain('Clean water for Nsawam')
        ->and($entries[1]['title'])->toBe('Drilling has started')
        ->and($entries[2]['title'])->toBe('You gave GH₵ 200.00')
        ->and($entries->pluck('title')->all())->not->toContain('Before you gave')
        ->not->toContain('Not yet published');

    $this->actingAs($this->user)->get(route('account.impact'))
        ->assertOk()
        ->assertSee('Drilling has started')
        ->assertSee('You gave GH₵ 200.00')
        ->assertSee('2 boreholes')
        ->assertDontSee('Before you gave')
        ->assertSee('data-impact-timeline', false);
});

it('suppresses a people count the public would not see either', function () {
    $project = Project::factory()->create();
    $cause = Cause::factory()->create(['project_id' => $project->id]);
    portalGift($this->donor, $cause, '50.00', '2026-03-10 10:00:00');

    $metric = ImpactMetric::factory()->countsPeople()->create(['project_id' => $project->id, 'name' => 'Widows supported']);
    ImpactMetricValue::create(['impact_metric_id' => $metric->id, 'period_start' => '2026-05-01', 'value' => 2]);

    $entries = app(ImpactTimeline::class)->for($this->donor);

    expect($entries->where('kind', 'figures'))->toBeEmpty();
});

it('shows a donor with no gifts where the story starts', function () {
    $this->actingAs($this->user)->get(route('account.impact'))
        ->assertOk()
        ->assertSee('Your story starts with a gift')
        ->assertDontSee('data-impact-timeline', false);
});

// ── The receipts archive ────────────────────────────────────────────────────

it('files receipts by tax year with the totals a return needs', function () {
    $plain = Cause::factory()->create(['title' => 'Harvest appeal']);
    $deductible = Cause::factory()->taxDeductible()->create(['title' => 'BrightPath Scholarships']);
    $issuer = app(ReceiptIssuer::class);

    $lastYear = $issuer->issue(portalGift($this->donor, $plain, '100.00', now()->subYear()->setDate(now()->year - 1, 6, 1)->toDateTimeString()));
    $thisYearA = $issuer->issue(portalGift($this->donor, $plain, '250.00', now()->startOfYear()->addMonth()->toDateTimeString()));
    $thisYearB = $issuer->issue(portalGift($this->donor, $deductible, '400.00', now()->startOfYear()->addMonths(2)->toDateTimeString()));

    // Somebody else's receipt is not in the archive.
    $issuer->issue(portalGift(Donor::factory()->create(), $plain, '999.00', now()->toDateTimeString()));

    $response = $this->actingAs($this->user)->get(route('account.receipts'))->assertOk();

    $response
        ->assertSee('data-receipt-year="'.now()->year.'"', false)
        ->assertSee('data-receipt-year="'.(now()->year - 1).'"', false)
        ->assertSeeInOrder([(string) now()->year, 'Given: GH₵ 650.00', (string) (now()->year - 1), 'Given: GH₵ 100.00'])
        ->assertSee($thisYearA->receipt_number)
        ->assertSee($thisYearB->receipt_number)
        ->assertSee($lastYear->receipt_number)
        ->assertDontSee('GH₵ 999.00');

    $deductibleTotal = $thisYearB->deductible_amount;

    if ($deductibleTotal instanceof Money && ! $deductibleTotal->isZero()) {
        $response->assertSee('Deductible: '.$deductibleTotal->format());
    }
});

it('counts the gifts still being receipted rather than hiding them', function () {
    $cause = Cause::factory()->create();
    portalGift($this->donor, $cause, '20.00', now()->toDateTimeString());

    $this->actingAs($this->user)->get(route('account.receipts'))
        ->assertOk()
        ->assertSee('One gift is still being receipted');
});

it('lets the account download a receipt for a gift made before the account existed', function () {
    $cause = Cause::factory()->create();
    $receipt = app(ReceiptIssuer::class)->issue(portalGift($this->donor, $cause, '75.00', now()->toDateTimeString()));

    expect($receipt->donation->user_id)->toBeNull();

    $this->actingAs($this->user)->get(route('receipts.download', $receipt))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // And nobody else's account does.
    $this->actingAs(User::factory()->donor()->create())->get(route('receipts.download', $receipt))->assertForbidden();
});

it('keeps the archive and the story behind a verified sign-in', function () {
    $this->get(route('account.receipts'))->assertRedirect(route('login'));
    $this->get(route('account.impact'))->assertRedirect(route('login'));

    $unverified = User::factory()->donor()->unverified()->create();
    $this->actingAs($unverified)->get(route('account.receipts'))->assertRedirect(route('verification.notice'));
});

it('shows the new tabs in the account shell', function () {
    $this->actingAs($this->user)->get(route('account.dashboard'))
        ->assertOk()
        ->assertSee(route('account.impact'))
        ->assertSee(route('account.receipts'));
});

it('still manages a standing gift from inside the account, without the emailed link', function () {
    // W1.3's third item was already built in Phase 9: the Regular giving
    // tab pauses, resumes, re-prices and cancels (GivingFlowTest). This
    // pins the tab's presence so it cannot quietly go.
    $this->actingAs($this->user)->get(route('account.giving'))
        ->assertOk()
        ->assertSee(__('Regular giving'));
});

it('does not list a receipt whose donation belongs to another donor even with the same email', function () {
    $other = Donor::factory()->create(['email' => 'other@example.com']);
    $cause = Cause::factory()->create();
    $receipt = app(ReceiptIssuer::class)->issue(portalGift($other, $cause, '60.00', now()->toDateTimeString()));

    $this->actingAs($this->user)->get(route('account.receipts'))->assertOk()->assertDontSee($receipt->receipt_number);

    expect(DonationReceipt::count())->toBe(1);
});
