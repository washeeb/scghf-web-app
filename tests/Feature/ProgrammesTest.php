<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Models\Division;
use App\Models\FocusArea;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\ProjectMilestone;
use App\Models\ProjectUpdate;
use App\Models\Testimonial;
use App\Models\ThemeSetting;
use App\Support\Anonymiser;
use App\ValueObjects\Money;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ═══════════════════════════════════════════════════════════════════════════
//  Divisions — the spine
// ═══════════════════════════════════════════════════════════════════════════

it('seeds the four divisions from the foundation profile', function () {
    $this->seed(DivisionSeeder::class);

    expect(Division::query()->pluck('slug')->sort()->values()->all())->toBe([
        'brightpath', 'every-soul-missions', 'legacy-of-love', 'life-spring',
    ]);
});

it('seeds each division with its focus areas', function () {
    $this->seed(DivisionSeeder::class);

    $legacy = Division::where('slug', 'legacy-of-love')->first();

    expect($legacy->focusAreas)->toHaveCount(8)
        ->and($legacy->focusAreas->pluck('name'))->toContain('Widows and widowers');
});

it('prefixes focus area slugs with the division so two divisions can share a name', function () {
    $this->seed(DivisionSeeder::class);

    // A focus area appears in a URL. Two divisions both running "mentorship"
    // would be a routing ambiguity, not merely a database collision.
    expect(FocusArea::where('slug', 'brightpath-mentorship')->exists())->toBeTrue();
});

it('is idempotent', function () {
    $this->seed(DivisionSeeder::class);
    $this->seed(DivisionSeeder::class);

    expect(Division::count())->toBe(4)
        ->and(FocusArea::count())->toBe(30);
});

it('does not overwrite a summary the foundation has edited', function () {
    $this->seed(DivisionSeeder::class);

    $division = Division::where('slug', 'life-spring')->first();
    $division->update(['summary' => 'Our own words about our health work.']);

    $this->seed(DivisionSeeder::class);

    expect($division->fresh()->summary)->toBe('Our own words about our health work.');
});

it('refuses to delete a locked division', function () {
    // Projects, causes, donations and years of reporting hang off these.
    $this->seed(DivisionSeeder::class);

    Division::where('slug', 'life-spring')->first()->delete();
})->throws(RuntimeException::class, 'cannot be deleted');

it('offers deactivation as the honest alternative to deletion', function () {
    $this->seed(DivisionSeeder::class);

    $division = Division::where('slug', 'life-spring')->first();
    $division->deactivate();

    expect($division->fresh()->is_active)->toBeFalse()
        ->and(Division::query()->active()->pluck('slug'))->not->toContain('life-spring')
        // Still there, still reportable, still referenced by everything.
        ->and(Division::find($division->id))->not->toBeNull();
});

it('allows deleting a division that is not structural', function () {
    $division = Division::factory()->create();

    $division->delete();

    expect(Division::withTrashed()->find($division->id)->trashed())->toBeTrue();
});

it('names a theme token for its colour rather than storing a hex', function () {
    // Colour in a data column would bypass theme_settings and its AA contrast
    // check exactly as a hex in a Blade template would.
    $this->seed([ThemeSettingsSeeder::class, DivisionSeeder::class]);

    foreach (Division::all() as $division) {
        expect($division->colour_token)->not->toStartWith('#')
            ->and(ThemeSetting::where('token', $division->colour_token)->exists())
            ->toBeTrue("division [{$division->slug}] names a token that does not exist");
    }
});

// ═══════════════════════════════════════════════════════════════════════════
//  Division scoping on content
// ═══════════════════════════════════════════════════════════════════════════

it('treats a null division as foundation-wide, not as missing data', function () {
    $division = Division::factory()->create();

    $scoped = Testimonial::create([
        'division_id' => $division->id,
        'author_name' => 'Ama',
        'quote' => 'The scholarship changed everything.',
    ]);

    $shared = Testimonial::create([
        'author_name' => 'Kofi',
        'quote' => 'The foundation is doing good work.',
    ]);

    expect($shared->isFoundationWide())->toBeTrue()
        ->and($scoped->isFoundationWide())->toBeFalse();

    // A division page shows its own content AND the foundation-wide content.
    $listed = Testimonial::query()->forDivision($division)->pluck('id');

    expect($listed)->toContain($scoped->id)->toContain($shared->id);
});

it('can exclude shared content when the question is what is actually tagged', function () {
    $division = Division::factory()->create();

    Testimonial::create(['division_id' => $division->id, 'author_name' => 'Ama', 'quote' => 'a']);
    $shared = Testimonial::create(['author_name' => 'Kofi', 'quote' => 'b']);

    $listed = Testimonial::query()->forDivision($division, includeShared: false)->pluck('id');

    expect($listed)->not->toContain($shared->id);
});

it('makes content foundation-wide rather than losing it if a division goes', function () {
    // ON DELETE SET NULL, not CASCADE. Content outlives the structure.
    $division = Division::factory()->create();
    $testimonial = Testimonial::create([
        'division_id' => $division->id, 'author_name' => 'Ama', 'quote' => 'a',
    ]);

    $division->forceDelete();

    expect($testimonial->fresh())->not->toBeNull()
        ->and($testimonial->fresh()->division_id)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════════
//  Projects
// ═══════════════════════════════════════════════════════════════════════════

it('stores a budget as integer pesewas and reads it back as Money', function () {
    $project = Project::factory()->create(['budget' => 2_500_000]);

    expect($project->fresh()->budget)->toEqualPesewas(2_500_000)
        ->and($project->fresh()->budget->format())->toBe('GH₵ 25,000.00')
        // The column keeps its `_minor` suffix so nothing in the schema reads
        // like a decimal, even though the attribute does not.
        ->and($project->fresh()->getRawOriginal('budget_minor'))->toBe(2_500_000);
});

it('accepts a Money straight into the budget column', function () {
    $project = Project::factory()->create(['budget' => Money::ofMajor('4735.00')]);

    expect($project->fresh()->budget)->toEqualPesewas(473_500);
});

it('refuses a float budget rather than storing it as pesewas', function () {
    // 25000.00 written as if it were 25,000 pesewas would understate the
    // budget by a factor of a hundred, silently.
    Project::factory()->create(['budget' => 25000.00]);
})->throws(InvalidArgumentException::class);

it('leaves a budget null rather than defaulting it to zero', function () {
    // Zero reads as "free". Null reads as "not yet costed", which is the truth
    // for a project published before its budget is agreed.
    $project = Project::factory()->create(['budget' => null]);

    expect($project->fresh()->budget)->toBeNull();
});

it('records the completion date when a project is marked complete', function () {
    $project = Project::factory()->create(['ends_on' => '2026-06-30']);

    $project->update(['status' => ProjectStatus::Completed]);

    expect($project->fresh()->completed_on->toDateString())->toBe('2026-06-30');
});

it('falls back to today when a completed project has no end date', function () {
    $project = Project::factory()->create(['ends_on' => null]);

    $project->update(['status' => ProjectStatus::Completed]);

    expect($project->fresh()->completed_on->isToday())->toBeTrue();
});

it('does not publicly list a project that is still only planned', function () {
    Project::factory()->draft()->create();
    $live = Project::factory()->create();

    expect(Project::query()->live()->pluck('id')->all())->toBe([$live->id]);
});

it('keeps a cancelled project visible rather than hiding the work', function () {
    // Quietly removing something that was announced and funded is exactly the
    // opacity a foundation should avoid.
    expect(ProjectStatus::Cancelled->isPubliclyListable())->toBeTrue()
        ->and(ProjectStatus::Cancelled->acceptsBeneficiaries())->toBeFalse();
});

it('reports progress from the schedule', function () {
    $project = Project::factory()->create([
        'starts_on' => now()->subDays(50)->toDateString(),
        'ends_on' => now()->addDays(50)->toDateString(),
    ]);

    expect($project->progressPercent())->toBeGreaterThan(45)->toBeLessThan(55);
});

it('reports a completed project as fully progressed whatever the dates say', function () {
    $project = Project::factory()->completed()->create([
        'starts_on' => now()->subDays(10)->toDateString(),
        'ends_on' => now()->addDays(90)->toDateString(),
    ]);

    expect($project->progressPercent())->toBe(100);
});

it('has no progress figure without both dates', function () {
    $project = Project::factory()->create(['ends_on' => null]);

    expect($project->progressPercent())->toBeNull();
});

it('scopes an update slug to its project', function () {
    $one = Project::factory()->create();
    $two = Project::factory()->create();

    // Two projects may both reasonably have a "first-term-report".
    ProjectUpdate::create(['project_id' => $one->id, 'title' => 'First term report', 'body' => 'x']);
    ProjectUpdate::create(['project_id' => $two->id, 'title' => 'First term report', 'body' => 'y']);

    expect(ProjectUpdate::where('slug', 'first-term-report')->count())->toBe(2);
});

it('links focus areas and partners to a project', function () {
    $division = Division::factory()->create();
    $project = Project::factory()->create(['division_id' => $division->id]);
    $area = FocusArea::factory()->create(['division_id' => $division->id]);

    $project->focusAreas()->attach($area);

    expect($project->fresh()->focusAreas)->toHaveCount(1)
        ->and($area->fresh()->projects)->toHaveCount(1);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Milestones and locations
// ═══════════════════════════════════════════════════════════════════════════

it('records when a milestone was achieved', function () {
    $milestone = ProjectMilestone::create([
        'project_id' => Project::factory()->create()->id,
        'title' => 'Fifty scholarships awarded',
        'status' => ProjectMilestone::STATUS_ACHIEVED,
    ]);

    expect($milestone->fresh()->achieved_on->isToday())->toBeTrue();
});

it('does not call a cancelled milestone overdue', function () {
    $project = Project::factory()->create();

    $missed = ProjectMilestone::create([
        'project_id' => $project->id, 'title' => 'Late', 'due_on' => now()->subMonth(),
    ]);

    $cancelled = ProjectMilestone::create([
        'project_id' => $project->id, 'title' => 'Called off',
        'due_on' => now()->subMonth(), 'status' => ProjectMilestone::STATUS_CANCELLED,
    ]);

    // Calling something off is a decision, not a failure to deliver.
    expect($missed->isOverdue())->toBeTrue()
        ->and($cancelled->isOverdue())->toBeFalse();
});

it('hides internal milestones from the public list', function () {
    $project = Project::factory()->create();

    ProjectMilestone::create(['project_id' => $project->id, 'title' => 'Grant report due', 'is_public' => false]);
    ProjectMilestone::create(['project_id' => $project->id, 'title' => 'Borehole commissioned']);

    expect($project->milestones()->visible()->count())->toBe(1);
});

it('keeps exactly one primary location per project', function () {
    $project = Project::factory()->create();

    $first = ProjectLocation::create([
        'project_id' => $project->id, 'name' => 'Asokwa', 'is_primary' => true,
    ]);

    ProjectLocation::create([
        'project_id' => $project->id, 'name' => 'Tamale', 'is_primary' => true,
    ]);

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and($project->locations()->where('is_primary', true)->count())->toBe(1);
});

it('falls back to the first location when none is flagged primary', function () {
    $project = Project::factory()->create();

    $first = ProjectLocation::create(['project_id' => $project->id, 'name' => 'Asokwa']);
    ProjectLocation::create(['project_id' => $project->id, 'name' => 'Tamale']);

    // A project with locations but none flagged is a data-entry gap, not a
    // project with no location.
    expect($project->fresh()->primaryLocation()->id)->toBe($first->id);
});

it('builds a readable location label', function () {
    $location = ProjectLocation::create([
        'project_id' => Project::factory()->create()->id,
        'name' => 'Asokwa site',
        'community' => 'Asokwa',
        'district' => 'Kumasi Metropolitan',
        'region' => 'Ashanti',
    ]);

    expect($location->fullLabel())->toBe('Asokwa, Kumasi Metropolitan, Ashanti');
});

it('keeps project site data separate from beneficiary location data', function () {
    // A community named on a project is published on purpose. A community
    // recorded against a beneficiary is personal data and is destroyed at
    // retention expiry. Nothing joins the two.
    expect(app(Anonymiser::class)->mustDestroy('community'))->toBeTrue()
        ->and(Schema::hasColumn('project_locations', 'beneficiary_id'))->toBeFalse();
});
