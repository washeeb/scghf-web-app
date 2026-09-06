<?php

declare(strict_types=1);

use App\Enums\CauseStatus;
use App\Enums\DonationStatus;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Causes\Pages\ListCauses;
use App\Filament\Resources\FocusAreas\Pages\ListFocusAreas;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Cause;
use App\Models\Division;
use App\Models\Donation;
use App\Models\FocusArea;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\ProjectMilestone;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Projects, areas of work and appeals
|--------------------------------------------------------------------------
|
| SEVEN SETTINGS DESCRIBED HOW TO GIVE AND APPEARED ON NO PAGE. The bank, the
| branch, the account name and number, the SWIFT code and the Mobile Money
| merchant details have been seeded since Phase 3 and shown nowhere. For a
| Ghanaian foundation that is not a minor omission: mobile money is how a very
| large share of giving actually happens, and a supporter who cannot find the
| merchant number gives nothing — and nobody ever learns that they tried.
|
| A PROJECT PAGE IS A TRANSPARENCY PAGE. Budget, progress, milestones, locations
| and documents together are what let somebody CHECK a claim rather than take
| it. Milestones are public only when marked so: an internal target the team
| missed is not a promise the foundation made to anybody.
|
| ANONYMITY IS ABOUT THE AMOUNT TOO. A donor wall showing "Anonymous — GH₵
| 5,000" beside named gifts identifies the anonymous donor to anybody who knows
| what they gave, which is exactly the person they were hiding it from.
|
| A CLOSED APPEAL KEEPS ITS PAGE. It is the record of what was raised and what
| it did, and deleting it turns every link anybody shared into a 404.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(DivisionSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function liveProject(array $attributes = []): Project
{
    return Project::create(array_merge([
        'title' => 'Boreholes in Bongo',
        'slug' => 'boreholes-in-bongo',
        'summary' => 'Clean water for four communities.',
        'status' => ProjectStatus::Active,
        'is_published' => true,
        'published_at' => now()->subDay(),
        'starts_on' => now()->subYear(),
    ], $attributes));
}

function liveCause(array $attributes = []): Cause
{
    return Cause::create(array_merge([
        'title' => 'Harvest appeal',
        'slug' => 'harvest-appeal',
        'summary' => 'Food parcels for fifty families.',
        'status' => CauseStatus::Active,
        'is_published' => true,
        'published_at' => now()->subDay(),
        'goal' => 500000,
    ], $attributes));
}

// ── Areas of work ───────────────────────────────────────────────────────────

it('lists the areas the foundation works in', function () {
    FocusArea::create([
        'division_id' => Division::first()->getKey(),
        'name' => 'Clean water',
        'slug' => 'clean-water',
        'is_active' => true,
    ]);

    $this->get(route('focus-areas.index'))->assertOk()->assertSee('Clean water');
});

it('lists an area that has no published project yet', function () {
    // A theme the foundation works in but has not published a project for is a
    // true statement about the organisation. Hiding it would make the page
    // describe the CMS rather than the foundation.
    FocusArea::create([
        'division_id' => Division::first()->getKey(),
        'name' => 'Livelihoods',
        'slug' => 'livelihoods',
        'is_active' => true,
    ]);

    $this->get(route('focus-areas.index'))
        ->assertOk()
        ->assertSee('Livelihoods')
        ->assertSee('No published projects yet');
});

// ── Projects ────────────────────────────────────────────────────────────────

it('shows a published project', function () {
    $project = liveProject();

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Boreholes in Bongo');
});

it('answers 404 for an unpublished project', function () {
    $project = liveProject(['is_published' => false]);

    $this->get(route('projects.show', $project))->assertNotFound();
});

it('publishes only the milestones marked public', function () {
    /*
     * ⚠ An internal target the team missed is not a promise the foundation made
     * to the public — and a transparency page that publishes every slip is one
     * a team stops recording honestly.
     */
    $project = liveProject();

    ProjectMilestone::create([
        'project_id' => $project->getKey(),
        'title' => 'Community meetings held',
        'is_public' => true,
    ]);

    ProjectMilestone::create([
        'project_id' => $project->getKey(),
        'title' => 'Internal budget review',
        'is_public' => false,
    ]);

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Community meetings held')
        ->assertDontSee('Internal budget review');
});

it('filters projects by region', function () {
    $bongo = liveProject();
    ProjectLocation::create(['project_id' => $bongo->getKey(), 'name' => 'Bongo', 'region' => 'Upper East', 'is_primary' => true]);

    $accra = liveProject(['title' => 'School kits', 'slug' => 'school-kits']);
    ProjectLocation::create(['project_id' => $accra->getKey(), 'name' => 'Accra', 'region' => 'Greater Accra', 'is_primary' => true]);

    $this->get(route('projects.index', ['region' => 'Upper East']))
        ->assertOk()
        ->assertSee('Boreholes in Bongo')
        ->assertDontSee('School kits');
});

it('counts a multi-year project in every year it ran', function () {
    /*
     * A project counts for a year if it was RUNNING in it, not only if it
     * started in it. Filtering a three-year programme out of years two and
     * three would make the foundation look like it stopped.
     */
    $project = liveProject([
        'starts_on' => now()->subYears(3),
        'ends_on' => now()->addYear(),
    ]);

    $middleYear = (int) now()->subYear()->format('Y');

    $this->get(route('projects.index', ['year' => $middleYear]))
        ->assertOk()
        ->assertSee($project->title);
});

it('offers only the regions work is actually happening in', function () {
    // A dropdown of all sixteen Ghanaian regions on a site with work in one is
    // fifteen dead ends, and the visitor who picks one learns only that the
    // filter appears broken.
    $project = liveProject();
    ProjectLocation::create(['project_id' => $project->getKey(), 'name' => 'Bongo', 'region' => 'Upper East', 'is_primary' => true]);

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Upper East')
        ->assertDontSee('Western North');
});

// ── Appeals ─────────────────────────────────────────────────────────────────

it('shows an appeal with its progress', function () {
    $cause = liveCause();
    $cause->forceFill(['raised_minor' => 125000, 'donation_count' => 4])->save();

    $this->get(route('causes.show', $cause))
        ->assertOk()
        ->assertSee('Harvest appeal')
        // 1,250 of 5,000 — a quarter of the way.
        ->assertSee('25% of the goal');
});

it('does not clamp an appeal that beat its goal', function () {
    // "We raised 140% of what we asked for" is the best news the page has, and
    // capping it at 100 hides it.
    $cause = liveCause();
    $cause->forceFill(['raised_minor' => 700000])->save();

    $this->get(route('causes.show', $cause))->assertOk()->assertSee('140% of the goal');
});

it('keeps the page of an appeal that has closed', function () {
    /*
     * ⚠ `isLive()` decides whether the page exists; `acceptsDonations()`
     * decides whether it takes money. Deleting a finished appeal turns every
     * link anybody ever shared into a 404 — and that page is the record of what
     * was raised.
     */
    $cause = liveCause(['ends_on' => now()->subWeek()]);

    expect($cause->acceptsDonations())->toBeFalse();

    $this->get(route('causes.show', $cause))
        ->assertOk()
        ->assertSee('This appeal has closed.');
});

it('shows a donor by name and never by amount', function () {
    /*
     * ⚠ `publicDonorName()` handles the name. The AMOUNT is a separate
     * question: "Anonymous — GH₵ 5,000" beside a list of named gifts identifies
     * the anonymous donor to anybody who knows what they gave.
     */
    $cause = liveCause();

    Donation::factory()->create([
        'cause_id' => $cause->getKey(),
        'status' => DonationStatus::Completed->value,
        'donor_name' => 'Ama Mensah',
        'is_anonymous' => false,
        // Deliberately not the goal amount: GH₵ 5,000.00 is what the progress
        // bar prints as the target, and asserting on it would pass or fail for
        // the wrong reason.
        'amount_minor' => 123400,
        'paid_at' => now(),
    ]);

    $this->get(route('causes.show', $cause))
        ->assertOk()
        ->assertSee('Ama Mensah')
        ->assertDontSee('GH₵ 1,234.00');
});

it('hides the name of an anonymous donor', function () {
    $cause = liveCause();

    Donation::factory()->create([
        'cause_id' => $cause->getKey(),
        'status' => DonationStatus::Completed->value,
        'donor_name' => 'Kofi Asante',
        'is_anonymous' => true,
        'paid_at' => now(),
    ]);

    $this->get(route('causes.show', $cause))
        ->assertOk()
        ->assertDontSee('Kofi Asante')
        ->assertSee('Anonymous');
});

it('respects the foundation switch over the whole donor wall', function () {
    // A donor who assumed their gift was private is not somebody to surprise,
    // so the feature has an off switch and it is honoured.
    app(Settings::class)->set('site.show_donor_wall', false);

    $cause = liveCause();

    Donation::factory()->create([
        'cause_id' => $cause->getKey(),
        'status' => DonationStatus::Completed->value,
        'donor_name' => 'Ama Mensah',
        'paid_at' => now(),
    ]);

    $this->get(route('causes.show', $cause))->assertOk()->assertDontSee('Ama Mensah');
});

it('does not claim tax relief without an approval on file', function () {
    /*
     * `is_tax_deductible` says the trustees consider this a qualifying cause.
     * It does NOT say the foundation holds the GRA approval that makes the
     * claim sayable, and reading the column directly is exactly the shortcut
     * that puts an unsupported claim in front of a donor.
     */
    $cause = liveCause(['is_tax_deductible' => true]);

    expect($cause->qualifiesForTaxRelief())->toBeFalse();

    $this->get(route('causes.show', $cause))
        ->assertOk()
        ->assertDontSee('may qualify for tax relief');
});

// ── Ways to give ────────────────────────────────────────────────────────────

it('shows the Mobile Money details the settings have always held', function () {
    /*
     * ⚠ Seeded in Phase 3, read by nothing until now. Mobile money is how a
     * very large share of Ghanaian giving happens.
     */
    app(Settings::class)->set('banking.momo_number', '024 123 4567');
    app(Settings::class)->set('banking.momo_name', 'SCGHF');

    $this->get(route('give'))
        ->assertOk()
        ->assertSee('024 123 4567')
        ->assertSee('Mobile Money');
});

it('shows bank details only when they are complete', function () {
    // Half a set of bank details is a transfer that bounces and a donor who has
    // to ring the office.
    app(Settings::class)->set('banking.bank_name', 'A Bank');

    $this->get(route('give'))->assertOk()->assertDontSee('Account number');
});

it('says so plainly when there is no way to give yet', function () {
    // A "ways to give" page with nothing on it must not look like a page that
    // failed to load.
    $this->get(route('give'))
        ->assertOk()
        ->assertSee('Our giving details are being set up');
});

// ── The admin screens ───────────────────────────────────────────────────────

it('opens the programme screens', function (string $page) {
    $this->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();

    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['projects.view', 'causes.view', 'divisions.manage', 'media.view']);

    $this->actingAs($user);

    Livewire::test($page)->assertOk();
})->with([ListProjects::class, ListCauses::class, ListFocusAreas::class]);
