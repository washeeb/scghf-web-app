<?php

declare(strict_types=1);

use App\Enums\CauseStatus;
use App\Enums\DonationStatus;
use App\Models\Cause;
use App\Models\CauseUpdate;
use App\Models\Donation;
use App\Models\ImpactMetric;
use App\Models\ImpactMetricValue;
use App\Models\Payout;
use App\Models\ScheduledMessage;
use App\Programmes\CauseUpdateNotifier;
use App\Support\DisclosureControl;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Giving levels, goals, impact and where the money went
|--------------------------------------------------------------------------
|
| GIVING LEVELS ARE THE HIGHEST-VALUE FIELD IN THE PHASE. "GH₵ 50 provides a
| school kit for one child" raises materially more than a blank amount box,
| because it answers what a hesitant donor is actually asking — not "how much
| should I give?" but "what does my money do?".
|
| THE GOAL-REACHED DEFAULT IS TO KEEP ACCEPTING. The brief left this to a
| judgement call. A foundation that hits its target and then refuses money is
| leaving gifts on the table, and a donor who has already decided to give is not
| somebody to turn away at the last step. What must NOT happen is taking money
| silently against a goal that is met — so the page says so either way.
|
| ⚠ THE EXPENDITURE LOG IS AGGREGATED, NEVER ITEMISED. A payout record carries a
| payee name and frequently the beneficiary it was spent on. Publishing the rows
| would publish who received school fees or a medical payment. Categories with
| too few payments are folded away, because a single medical payment beside a
| known beneficiary is an identification.
|
| ⚠ EVERY PUBLISHED METRIC GOES THROUGH `publishedTotal()`. Never `total()`.
| "3 widows supported in Bongo" identifies those three women to anybody who
| lives there.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function appeal(array $attributes = []): Cause
{
    return Cause::create(array_merge([
        'title' => 'School kits',
        'slug' => 'school-kits',
        'status' => CauseStatus::Active,
        'is_published' => true,
        'published_at' => now()->subDay(),
        'goal' => 100000,
    ], $attributes));
}

// ── Giving levels ───────────────────────────────────────────────────────────

it('shows what each amount buys on the appeal page', function () {
    $cause = appeal(['giving_levels' => [
        ['amount_minor' => 5000, 'label' => 'A school kit', 'description' => 'Books, a bag and a uniform.'],
        ['amount_minor' => 25000, 'label' => 'A term of fees'],
    ]]);

    $this->get(route('causes.show', $cause))
        ->assertOk()
        ->assertSee('A school kit')
        ->assertSee('Books, a bag and a uniform.')
        ->assertSee('GH₵ 50.00');
});

it('offers the appeal levels on the donation form instead of the site presets', function () {
    $cause = appeal(['giving_levels' => [
        ['amount_minor' => 5000, 'label' => 'A school kit'],
    ]]);

    $this->get(route('donate', ['cause' => $cause->slug]))
        ->assertOk()
        ->assertSee('A school kit');
});

it('drops a malformed level rather than taking the page down', function () {
    // JSON edited through a form. One bad row must not break the page the
    // foundation raises money on.
    $cause = appeal(['giving_levels' => [
        ['label' => 'No amount at all'],
        ['amount_minor' => 5000, 'label' => 'A school kit'],
    ]]);

    expect($cause->givingLevels())->toHaveCount(1);

    $this->get(route('causes.show', $cause))->assertOk();
});

it('carries the chosen level through to the form', function () {
    // Somebody who clicked "Give GH₵ 50 — a school kit" has already chosen.
    $cause = appeal();

    $this->get(route('donate', ['cause' => $cause->slug, 'amount' => '50.00']))
        ->assertOk()
        ->assertSee('value="50.00"', escape: false);
});

// ── The minimum ─────────────────────────────────────────────────────────────

it('lets an appeal set a floor above the site one', function () {
    /*
     * The two mean different things. The site floor is commercial — a gift
     * smaller than the transaction fee costs money to accept. An appeal's own
     * is editorial: the smallest gift that buys anything in its terms.
     */
    $cause = appeal(['min_donation_minor' => 5000]);

    $this->post(route('donate.store'), [
        'amount' => '10.00',
        'cause' => $cause->slug,
        'donor_name' => 'Ama',
        'donor_email' => 'ama@example.test',
        'consent' => '1',
    ])->assertSessionHasErrors('amount');
});

// ── Reaching the goal ───────────────────────────────────────────────────────

it('keeps accepting past the goal by default', function () {
    /*
     * ⚠ The decision this phase asked for. A foundation that hits its target
     * and refuses money is leaving gifts on the table, and a donor who has
     * already decided to give is not somebody to turn away at the last step.
     */
    $cause = appeal();
    $cause->forceFill(['raised_minor' => 150000])->save();

    expect($cause->hasReachedItsGoal())->toBeTrue()
        ->and($cause->acceptsDonations())->toBeTrue();
});

it('says the target has been reached whatever it does next', function () {
    // Taking money SILENTLY against a met goal is the dishonest version of
    // "keep accepting".
    $cause = appeal();
    $cause->forceFill(['raised_minor' => 150000])->save();

    $this->get(route('causes.show', $cause))
        ->assertOk()
        ->assertSee('We have reached the target for this appeal.');
});

it('stops accepting when the appeal says to close', function () {
    $cause = appeal(['goal_reached_behaviour' => Cause::ON_GOAL_CLOSE]);
    $cause->forceFill(['raised_minor' => 150000])->save();

    expect($cause->fresh()->acceptsDonations())->toBeFalse();
});

it('sends donors on when the appeal says to redirect', function () {
    $other = appeal(['title' => 'Clean water', 'slug' => 'clean-water']);

    $cause = appeal([
        'title' => 'Full appeal',
        'slug' => 'full-appeal',
        'goal_reached_behaviour' => Cause::ON_GOAL_REDIRECT,
        'redirect_cause_id' => $other->getKey(),
    ]);
    $cause->forceFill(['raised_minor' => 150000])->save();

    expect($cause->fresh()->redirectTarget()?->slug)->toBe('clean-water');

    $this->get(route('causes.show', $cause))->assertOk()->assertSee('Clean water');
});

it('does not bounce a donor into a second full appeal', function () {
    // A redirect chain into another appeal that is itself closed would send
    // somebody between two pages that both decline their gift.
    $other = appeal([
        'title' => 'Also full',
        'slug' => 'also-full',
        'goal_reached_behaviour' => Cause::ON_GOAL_CLOSE,
    ]);
    $other->forceFill(['raised_minor' => 150000])->save();

    $cause = appeal([
        'title' => 'Full appeal',
        'slug' => 'full-appeal',
        'goal_reached_behaviour' => Cause::ON_GOAL_REDIRECT,
        'redirect_cause_id' => $other->getKey(),
    ]);
    $cause->forceFill(['raised_minor' => 150000])->save();

    expect($cause->fresh()->redirectTarget())->toBeNull();
});

// ── Where the money went ────────────────────────────────────────────────────

it('publishes spending by category and never by payment', function () {
    /*
     * ⚠ A payout record carries a payee name and often the beneficiary it was
     * spent on. Publishing the rows would publish who received school fees or a
     * medical payment.
     */
    $cause = appeal();
    $minimum = app(DisclosureControl::class)->minimumGroupSize();

    foreach (range(1, $minimum) as $index) {
        Payout::factory()->create([
            'cause_id' => $cause->getKey(),
            'category' => Payout::CATEGORY_SCHOOL_FEES,
            'payee_name' => 'A named supplier '.$index,
            'amount' => 10000,
            'paid_at' => now(),
        ]);
    }

    $this->get(route('causes.show', $cause))
        ->assertOk()
        ->assertSee('Where the money went')
        ->assertSee('School Fees')
        ->assertDontSee('A named supplier 1');
});

it('folds a category with too few payments into Other', function () {
    // A single medical payment beside a known beneficiary is an identification,
    // and the minimum group size exists for exactly that shape of leak.
    $cause = appeal();

    Payout::factory()->create([
        'cause_id' => $cause->getKey(),
        'category' => Payout::CATEGORY_MEDICAL,
        'amount' => 45000,
        'paid_at' => now(),
    ]);

    $this->get(route('causes.show', $cause))
        ->assertOk()
        ->assertDontSee('Medical')
        ->assertSee('Other');
});

it('counts only money that has actually left', function () {
    // An approved payout that has not been sent is a commitment, and publishing
    // it as spending overstates what the foundation has done.
    $cause = appeal();

    Payout::factory()->create([
        'cause_id' => $cause->getKey(),
        'category' => Payout::CATEGORY_FOOD,
        'amount' => 50000,
        'paid_at' => null,
    ]);

    $this->get(route('causes.show', $cause))->assertOk()->assertDontSee('Where the money went');
});

// ── The impact page ─────────────────────────────────────────────────────────

it('publishes what was raised beside what was paid out', function () {
    /*
     * Publishing "raised" alone is the number every charity publishes and it
     * answers nothing a sceptical donor is asking. What went out is the claim
     * that can be checked.
     */
    Donation::factory()->create([
        'status' => DonationStatus::Completed->value,
        'amount_minor' => 250000,
        'paid_at' => now(),
    ]);

    Payout::factory()->create(['amount' => 100000, 'paid_at' => now()]);

    $this->get(route('impact'))
        ->assertOk()
        ->assertSee('GH₵ 2,500.00')
        ->assertSee('GH₵ 1,000.00');
});

it('withholds a metric that would identify the people it counts', function () {
    /*
     * ⚠ The check this whole disclosure layer exists for. "3 widows supported
     * in Bongo" identifies those three women to anybody who lives there.
     */
    $metric = ImpactMetric::create([
        'name' => 'Widows supported',
        'slug' => 'widows-supported',
        'counts_people' => true,
        'is_public' => true,
    ]);

    ImpactMetricValue::create([
        'impact_metric_id' => $metric->getKey(),
        'period_start' => now()->subMonth(),
        'value' => 3,
    ]);

    expect($metric->publishedTotal())->toBeNull();

    $this->get(route('impact'))->assertOk()->assertDontSee('Widows supported');
});

it('publishes a metric that counts things rather than people', function () {
    // One borehole is one borehole.
    $metric = ImpactMetric::create([
        'name' => 'Boreholes drilled',
        'slug' => 'boreholes-drilled',
        'counts_people' => false,
        'is_public' => true,
    ]);

    ImpactMetricValue::create([
        'impact_metric_id' => $metric->getKey(),
        'period_start' => now()->subMonth(),
        'value' => 3,
    ]);

    $this->get(route('impact'))->assertOk()->assertSee('Boreholes drilled');
});

// ── Telling the donors ──────────────────────────────────────────────────────

it('emails an update to the people who gave to that appeal', function () {
    /*
     * The commonest reason a donor does not give a second time is that they
     * never heard what the first gift did.
     */
    $this->seed(MessageTemplateSeeder::class);

    $cause = appeal();

    Donation::factory()->create([
        'cause_id' => $cause->getKey(),
        'status' => DonationStatus::Completed->value,
        'donor_email' => 'ama@example.test',
        'donor_name' => 'Ama',
        'consent_email' => true,
        'is_anonymous' => false,
        'paid_at' => now(),
    ]);

    $update = CauseUpdate::create([
        'cause_id' => $cause->getKey(),
        'title' => 'The kits arrived',
        'slug' => 'the-kits-arrived',
        'body' => '<p>All ninety of them.</p>',
        'is_published' => true,
        'published_at' => now(),
    ]);

    expect(app(CauseUpdateNotifier::class)->notify($update))->toBe(1)
        ->and(ScheduledMessage::count())->toBe(1);
});

it('does not email somebody who never agreed to updates', function () {
    $this->seed(MessageTemplateSeeder::class);

    $cause = appeal();

    Donation::factory()->create([
        'cause_id' => $cause->getKey(),
        'status' => DonationStatus::Completed->value,
        'donor_email' => 'quiet@example.test',
        'consent_email' => false,
        'paid_at' => now(),
    ]);

    $update = CauseUpdate::create([
        'cause_id' => $cause->getKey(),
        'title' => 'An update',
        'slug' => 'an-update',
        'body' => '<p>Something.</p>',
        'is_published' => true,
    ]);

    expect(app(CauseUpdateNotifier::class)->notify($update))->toBe(0);
});

it('sends one email to a donor who gave five times', function () {
    // The obvious implementation sends five, and the donor concludes the
    // foundation cannot count.
    $this->seed(MessageTemplateSeeder::class);

    $cause = appeal();

    foreach (range(1, 5) as $ignored) {
        Donation::factory()->create([
            'cause_id' => $cause->getKey(),
            'status' => DonationStatus::Completed->value,
            'donor_email' => 'ama@example.test',
            'donor_name' => 'Ama',
            'consent_email' => true,
            'is_anonymous' => false,
            'paid_at' => now(),
        ]);
    }

    $update = CauseUpdate::create([
        'cause_id' => $cause->getKey(),
        'title' => 'An update',
        'slug' => 'an-update',
        'body' => '<p>Something.</p>',
        'is_published' => true,
    ]);

    expect(app(CauseUpdateNotifier::class)->notify($update))->toBe(1);
});

// ── Peer-to-peer stays off ──────────────────────────────────────────────────

it('keeps peer-to-peer fundraising switched off', function () {
    /*
     * The brief said "build if I confirmed I want it; otherwise scaffold the
     * tables and hide the UI behind a feature flag". It was not confirmed, so
     * the flag stays OFF and the tables stay unused — which is this project's
     * definition of a genuine deferral rather than a gap.
     */
    expect(config('features.p2p_fundraising'))->toBeFalse();
});
