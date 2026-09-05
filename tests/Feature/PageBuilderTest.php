<?php

declare(strict_types=1);

use App\Blocks\BlockRegistry;
use App\Blocks\SectionSettings;
use App\Filament\Blocks\BlockFieldFactory;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\User;
use App\Policies\BasePolicy;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The page builder
|--------------------------------------------------------------------------
|
| ONE SOURCE FOR THE FORM AND THE VALIDATION. A block's field list generates
| both its admin fields and its validation rules. Two hand-written lists agree
| until somebody changes one — and the failure mode is a field an editor can
| fill in that is then silently dropped.
|
| A CLOSED VOCABULARY. Backgrounds, spacings, widths and alignments are fixed
| lists that resolve to theme tokens. Nothing an editor stores reaches a class
| attribute, so a `settings` column edited by hand can only ever produce a class
| the code already contains.
|
| A REVISION IS TAKEN BEFORE THE SAVE. One taken after is a record of a change
| that already happened — useful for an audit, useless for undoing anything.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    BasePolicy::forgetKnownPermissions();
});

/** Somebody who may edit the website. */
function pageEditor(): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['pages.view', 'pages.create', 'pages.update', 'pages.delete']);

    return $user;
}

// ── The registry drives everything ──────────────────────────────────────────

it('offers every block in the registry, and nothing else', function () {
    /*
     * The curated library. Blueprint §3.2 rejected freeform blocks, so what an
     * editor can place is exactly what the design supports and what has a Blade
     * view respecting the theme tokens.
     */
    $registry = app(BlockRegistry::class);

    expect($registry->keys())->not->toBeEmpty()
        ->and($registry->has('hero'))->toBeTrue()
        ->and($registry->has('anything-else'))->toBeFalse();
});

it('builds admin fields for every block without any admin code', function () {
    // A block added to the registry appears in the panel with no admin work,
    // which is what keeps twenty block types maintainable.
    $factory = app(BlockFieldFactory::class);

    foreach (app(BlockRegistry::class)->all() as $definition) {
        $fields = $factory->fieldsFor($definition);

        expect($fields)->toHaveCount(count($definition->fields));
    }
});

it('names every admin field so it lands in the data column', function () {
    /*
     * `data.heading`, not `heading`. Without the prefix the value would be
     * written to a column of that name — which does not exist — and silently
     * discarded by the repeater.
     */
    $definition = app(BlockRegistry::class)->get('hero');

    foreach (app(BlockFieldFactory::class)->fieldsFor($definition) as $field) {
        expect($field->getName())->toStartWith('data.');
    }
});

// ── The closed vocabulary ───────────────────────────────────────────────────

it('turns settings into classes from a fixed list', function () {
    $settings = new SectionSettings(['background' => 'brand', 'padding' => 'large']);

    expect($settings->sectionClasses())
        ->toContain('bg-[var(--brand-primary)]')
        ->toContain('py-16');
});

it('carries the foreground token with the background', function () {
    // A band whose background changed and whose text did not is the commonest
    // way a themed page becomes unreadable.
    expect((new SectionSettings(['background' => 'brand']))->sectionClasses())
        ->toContain('text-[var(--text-on-brand)]');
});

it('ignores a setting that is not in the vocabulary', function () {
    /*
     * The security property. `settings` is a JSON column, and a value from it
     * must never reach a class attribute — so the renderer LOOKS UP the stored
     * value rather than interpolating it.
     */
    $settings = new SectionSettings([
        'background' => 'bg-red-500" onload="alert(1)',
        'padding' => '../../etc',
    ]);

    $classes = $settings->sectionClasses();

    expect($classes)->not->toContain('onload')
        ->and($classes)->not->toContain('etc')
        ->and($classes)->toContain('py-10');
});

it('falls back to the defaults for a section nobody has styled', function () {
    // Null is the normal state, not a backfill waiting to happen.
    $settings = new SectionSettings(null);

    expect($settings->get('padding'))->toBe('medium')
        ->and($settings->containerClasses())->toContain('max-w-6xl');
});

it('offers the admin exactly the options the renderer can draw', function () {
    /*
     * Generated from the same constants the renderer reads. A background
     * offered in the panel is by construction one the renderer knows how to
     * draw, and neither list can grow without the other.
     */
    $options = SectionSettings::options();

    foreach (array_keys($options['background']) as $value) {
        expect(SectionSettings::BACKGROUNDS)->toHaveKey($value);
    }

    foreach (array_keys($options['padding']) as $value) {
        expect(SectionSettings::PADDING)->toHaveKey($value);
    }
});

// ── Revisions ───────────────────────────────────────────────────────────────

it('snapshots every column an editor can set', function () {
    /*
     * ⚠ A snapshot that omits a column is a restore that silently clears it.
     * `settings`, `visible_from` and `visible_until` were absent when the
     * presentation columns were added, which would have reset every block on a
     * page to the site defaults on the first restore anybody performed.
     */
    $page = Page::factory()->create();

    $page->sections()->create([
        'block_type' => 'rich-text',
        'data' => ['body' => '<p>Original</p>'],
        'settings' => ['background' => 'brand', 'padding' => 'large'],
        'visible_from' => now()->subDay(),
    ]);

    $snapshot = $page->snapshot()->decoded();

    expect($snapshot['sections'][0])->toHaveKeys([
        'block_type', 'name', 'data', 'settings', 'sort_order',
        'is_visible', 'visible_from', 'visible_until',
    ]);
});

it('puts the presentation settings back on a restore', function () {
    $page = Page::factory()->create();

    $page->sections()->create([
        'block_type' => 'rich-text',
        'data' => ['body' => '<p>Original</p>'],
        'settings' => ['background' => 'brand'],
    ]);

    $revision = $page->snapshot();

    $page->sections()->delete();
    $page->sections()->create([
        'block_type' => 'rich-text',
        'data' => ['body' => '<p>Replaced</p>'],
        'settings' => ['background' => 'none'],
    ]);

    $revision->restore();

    $section = $page->fresh()->sections()->first();

    expect($section->data['body'])->toBe('<p>Original</p>')
        ->and($section->presentation()->get('background'))->toBe('brand');
});

it('makes restoring itself undoable', function () {
    // An editor who restores the wrong version must not be stuck with it.
    $page = Page::factory()->create(['title' => 'First']);
    $revision = $page->snapshot();

    $page->forceFill(['title' => 'Second'])->save();

    $revision->restore();

    expect($page->fresh()->title)->toBe('First')
        ->and($page->revisions()->count())->toBe(2);
});

// ── The screen ──────────────────────────────────────────────────────────────

it('opens the page list', function () {
    $this->actingAs(pageEditor());

    Livewire::test(ListPages::class)->assertOk();
});

it('saves a page with a block on it', function () {
    $this->actingAs(pageEditor());

    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->ulid])
        ->fillForm([
            'title' => 'Our Story',
            'slug' => 'our-story',
            'status' => 'draft',
            'sections' => [
                ['block_type' => 'rich-text', 'data' => ['body' => '<p>Hello</p>']],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $section = $page->fresh()->sections()->first();

    expect($section)->not->toBeNull()
        ->and($section->block_type)->toBe('rich-text');
});

it('takes a revision before the save, not after', function () {
    /*
     * The distinction that makes revisions useful. One taken after the write
     * records the change that already happened; the one taken before is the
     * state somebody can return to.
     */
    $this->actingAs(pageEditor());

    $page = Page::factory()->create(['title' => 'Before']);

    Livewire::test(EditPage::class, ['record' => $page->ulid])
        ->fillForm(['title' => 'After'])
        ->call('save')
        ->assertHasNoFormErrors();

    $revision = $page->fresh()->revisions()->latest('revision_number')->first();

    expect($revision)->not->toBeNull()
        ->and($revision->decoded()['page']['title'])->toBe('Before');
});

it('keeps the blocks in the order they were dragged into', function () {
    $page = Page::factory()->create();

    $page->sections()->create(['block_type' => 'rich-text', 'sort_order' => 2, 'data' => ['body' => 'Second']]);
    $page->sections()->create(['block_type' => 'cta-band', 'sort_order' => 1, 'data' => ['heading' => 'First']]);

    expect($page->sections()->orderBy('sort_order')->pluck('block_type')->all())
        ->toBe(['cta-band', 'rich-text']);
});

// ── What the list makes visible ─────────────────────────────────────────────

it('counts published pages that would render blank', function () {
    /*
     * A published page with no blocks is a heading over white space — the
     * commonest way a CMS goes live looking broken, and invisible in a list
     * that only shows a status.
     */
    Page::factory()->create(['status' => 'published']);

    $withBlocks = Page::factory()->create(['status' => 'published']);
    $withBlocks->sections()->create(['block_type' => 'rich-text', 'data' => ['body' => 'x']]);

    Page::factory()->create(['status' => 'draft']);

    expect(PageResource::getNavigationBadge())->toBe('1');
});

it('shows no badge when every published page has content', function () {
    $page = Page::factory()->create(['status' => 'published']);
    $page->sections()->create(['block_type' => 'rich-text', 'data' => ['body' => 'x']]);

    expect(PageResource::getNavigationBadge())->toBeNull();
});

// ── Blocks that have gone away ──────────────────────────────────────────────

it('does not render a block whose type was removed from the registry', function () {
    /*
     * Removing a block from the code must not take down every page that still
     * has one placed — the section is dropped, the page still loads.
     */
    $page = Page::factory()->create();

    $section = $page->sections()->create([
        'block_type' => 'a-block-that-no-longer-exists',
        'data' => [],
    ]);

    expect($section->isRenderable())->toBeFalse()
        ->and($section->definition())->toBeNull();
});

it('respects a block scheduled for later', function () {
    // An appeal block that appears on a date and disappears by itself, so
    // nobody has to remember to take it down.
    $page = Page::factory()->create();

    $future = $page->sections()->create([
        'block_type' => 'cta-band',
        'data' => ['heading' => 'Christmas appeal'],
        'visible_from' => now()->addWeek(),
    ]);

    $expired = $page->sections()->create([
        'block_type' => 'cta-band',
        'data' => ['heading' => 'Last year'],
        'visible_until' => now()->subDay(),
    ]);

    expect($future->isRenderable())->toBeFalse()
        ->and($expired->isRenderable())->toBeFalse();
});

it('falls back to a block\'s declared default for a field saved before it existed', function () {
    /*
     * A block gaining a field must not break pages saved before that field
     * existed — they get the default rather than null, and the view does not
     * have to defend against every key.
     */
    $section = new PageSection([
        'block_type' => 'hero',
        'data' => ['heading' => 'Only the heading'],
    ]);

    expect($section->field('overlay_opacity'))->toBe(55);
});
