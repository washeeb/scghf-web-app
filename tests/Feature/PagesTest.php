<?php

declare(strict_types=1);

use App\Enums\PageStatus;
use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\BlockTypeSeeder;
use Database\Seeders\PageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Paths ────────────────────────────────────────────────────────────────────

it('derives the path from the slug', function () {
    $page = Page::create(['title' => 'About Us', 'slug' => 'about']);

    expect($page->path)->toBe('/about');
});

it('slugifies a title when no slug is given', function () {
    $page = Page::create(['title' => 'Vision & Mission']);

    expect($page->slug)->toBe('vision-mission')
        ->and($page->path)->toBe('/vision-mission');
});

it('nests the path under the parent', function () {
    $about = Page::create(['title' => 'About', 'slug' => 'about']);
    $child = Page::create(['title' => 'Leadership', 'slug' => 'leadership', 'parent_id' => $about->id]);

    expect($child->path)->toBe('/about/leadership');
});

it('refuses two pages at the same top-level path', function () {
    Page::create(['title' => 'About', 'slug' => 'about']);
    Page::create(['title' => 'About Again', 'slug' => 'about']);
})->throws(QueryException::class);

it('allows the same slug under different parents', function () {
    $a = Page::create(['title' => 'About', 'slug' => 'about']);
    $b = Page::create(['title' => 'Shop', 'slug' => 'shop']);

    $one = Page::create(['title' => 'Team', 'slug' => 'team', 'parent_id' => $a->id]);
    $two = Page::create(['title' => 'Team', 'slug' => 'team', 'parent_id' => $b->id]);

    expect($one->path)->toBe('/about/team')
        ->and($two->path)->toBe('/shop/team');
});

it('moves the whole subtree when a parent is renamed', function () {
    // The bug a materialised path invites: a child keeps an address nothing
    // links to, and the page still renders, so nobody notices.
    $about = Page::create(['title' => 'About', 'slug' => 'about']);
    $child = Page::create(['title' => 'Leadership', 'slug' => 'leadership', 'parent_id' => $about->id]);
    $grandchild = Page::create(['title' => 'Trustees', 'slug' => 'trustees', 'parent_id' => $child->id]);

    $about->update(['slug' => 'who-we-are']);

    expect($child->fresh()->path)->toBe('/who-we-are/leadership')
        ->and($grandchild->fresh()->path)->toBe('/who-we-are/leadership/trustees');
});

it('survives a parent cycle without hanging', function () {
    // An admin CAN do this in a form. Without the guard, buildPath loops until
    // the request times out.
    $a = Page::create(['title' => 'A', 'slug' => 'a']);
    $b = Page::create(['title' => 'B', 'slug' => 'b', 'parent_id' => $a->id]);

    $a->forceFill(['parent_id' => $b->id])->save();

    expect($a->fresh()->path)->toBeString();
});

// ── The homepage ─────────────────────────────────────────────────────────────

it('serves the homepage from / regardless of its slug', function () {
    $page = Page::create(['title' => 'Welcome', 'slug' => 'welcome']);
    $page->setAsHomepage();

    expect($page->fresh()->path)->toBe('/');
});

it('demotes the previous homepage so only one exists', function () {
    $first = Page::create(['title' => 'First', 'slug' => 'first']);
    $first->setAsHomepage();

    $second = Page::create(['title' => 'Second', 'slug' => 'second']);
    $second->setAsHomepage();

    expect(Page::where('is_homepage', true)->count())->toBe(1)
        ->and($second->fresh()->is_homepage)->toBeTrue()
        ->and($first->fresh()->is_homepage)->toBeFalse()
        // The demoted page needs a real path back, not a lingering '/'.
        ->and($first->fresh()->path)->toBe('/first');
});

// ── Publication ──────────────────────────────────────────────────────────────

it('is not live while it is a draft', function () {
    expect(Page::create(['title' => 'Draft', 'slug' => 'draft'])->isLive())->toBeFalse();
});

it('goes live when published', function () {
    $page = Page::create(['title' => 'P', 'slug' => 'p']);
    $page->publish();

    expect($page->fresh()->isLive())->toBeTrue()
        ->and($page->fresh()->status)->toBe(PageStatus::Published);
});

it('holds a scheduled page until its date passes', function () {
    $page = Page::create(['title' => 'P', 'slug' => 'p']);
    $page->publish(now()->addDay());

    expect($page->fresh()->status)->toBe(PageStatus::Scheduled)
        ->and($page->fresh()->isLive())->toBeFalse();

    $this->travel(2)->days();

    // No one touches it — the date passing is what makes it live.
    expect($page->fresh()->isLive())->toBeTrue();
});

it('only returns live pages from the live scope', function () {
    Page::create(['title' => 'D', 'slug' => 'd']);
    Page::create(['title' => 'P', 'slug' => 'p'])->publish();
    Page::create(['title' => 'S', 'slug' => 's'])->publish(now()->addWeek());

    expect(Page::live()->pluck('slug')->all())->toBe(['p']);
});

// ── Locked system pages ──────────────────────────────────────────────────────

it('refuses to delete a locked system page', function () {
    $this->seed(PageSeeder::class);

    // The footer and Paystack merchant profile link here. A 404 is a
    // compliance issue, not a broken link.
    Page::where('slug', 'privacy-policy')->first()->delete();
})->throws(RuntimeException::class);

it('allows an unlocked page to be deleted', function () {
    $page = Page::create(['title' => 'Temp', 'slug' => 'temp']);
    $page->delete();

    expect($page->fresh()->trashed())->toBeTrue();
});

it('lets a locked page be unpublished instead', function () {
    $this->seed(PageSeeder::class);

    $page = Page::where('slug', 'privacy-policy')->first();
    $page->publish();
    $page->unpublish();

    expect($page->fresh()->isLive())->toBeFalse();
});

// ── Sections ─────────────────────────────────────────────────────────────────

it('orders sections and hides invisible ones', function () {
    $this->seed(BlockTypeSeeder::class);
    $page = Page::create(['title' => 'Home', 'slug' => 'home']);

    $page->sections()->createMany([
        ['block_type' => 'rich-text', 'sort_order' => 2, 'data' => ['body' => 'second']],
        ['block_type' => 'hero', 'sort_order' => 1, 'data' => ['heading' => 'first']],
        ['block_type' => 'faq', 'sort_order' => 3, 'is_visible' => false],
    ]);

    expect($page->sections()->pluck('block_type')->all())
        ->toBe(['hero', 'rich-text', 'faq'])
        ->and(PageSection::visible()->pluck('block_type')->all())
        ->toBe(['hero', 'rich-text']);
});

it('falls back to the block default for a field saved before it existed', function () {
    $section = PageSection::create([
        'page_id' => Page::create(['title' => 'P', 'slug' => 'p'])->id,
        'block_type' => 'hero',
        'data' => ['heading' => 'Hello'],
    ]);

    // overlay_opacity was never saved on this section.
    expect($section->field('heading'))->toBe('Hello')
        ->and($section->field('overlay_opacity'))->toBe(55);
});

it('stops rendering a section whose block was removed from the registry', function () {
    $section = PageSection::create([
        'page_id' => Page::create(['title' => 'P', 'slug' => 'p'])->id,
        'block_type' => 'a-block-that-no-longer-exists',
    ]);

    // Removing a block from the code must not take down every page that has
    // one placed — the section simply stops rendering.
    expect($section->definition())->toBeNull()
        ->and($section->isRenderable())->toBeFalse()
        ->and($section->view())->toBeNull();
});

it('respects a scheduling window on a section', function () {
    $page = Page::create(['title' => 'P', 'slug' => 'p']);

    $future = PageSection::create(['page_id' => $page->id, 'block_type' => 'cta-band', 'visible_from' => now()->addDay()]);
    $expired = PageSection::create(['page_id' => $page->id, 'block_type' => 'cta-band', 'visible_until' => now()->subDay()]);

    expect($future->isRenderable())->toBeFalse()
        ->and($expired->isRenderable())->toBeFalse();
});

// ── Revisions ────────────────────────────────────────────────────────────────

it('restores a page and its blocks from a revision', function () {
    $page = Page::create(['title' => 'Original', 'slug' => 'orig']);
    $page->sections()->create(['block_type' => 'hero', 'data' => ['heading' => 'First heading'], 'sort_order' => 1]);

    $revision = $page->snapshot('Before the edit');

    $page->update(['title' => 'Changed']);
    $page->sections()->delete();
    $page->sections()->create(['block_type' => 'faq', 'data' => ['heading' => 'Replaced'], 'sort_order' => 1]);

    $revision->restore();

    $restored = $page->fresh();
    expect($restored->title)->toBe('Original')
        ->and($restored->sections)->toHaveCount(1)
        ->and($restored->sections->first()->block_type)->toBe('hero')
        ->and($restored->sections->first()->data['heading'])->toBe('First heading');
});

it('makes a restore itself undoable', function () {
    $page = Page::create(['title' => 'One', 'slug' => 'one']);
    $first = $page->snapshot();

    $page->update(['title' => 'Two']);
    $first->restore();

    // Restoring snapshots the current state first, so an editor who restores
    // the wrong revision is not stuck.
    expect($page->revisions()->count())->toBe(2)
        ->and($page->revisions()->latest('revision_number')->first()->decoded()['page']['title'])->toBe('Two');
});

it('numbers revisions sequentially per page', function () {
    $page = Page::create(['title' => 'P', 'slug' => 'p']);

    expect($page->snapshot()->revision_number)->toBe(1)
        ->and($page->snapshot()->revision_number)->toBe(2);
});

// ── Seeder ───────────────────────────────────────────────────────────────────

it('seeds the sitemap as drafts so nothing is public before it is written', function () {
    $this->seed(PageSeeder::class);

    expect(Page::count())->toBeGreaterThan(20)
        ->and(Page::live()->count())->toBe(0)
        ->and(Page::where('is_homepage', true)->count())->toBe(1)
        ->and(Page::where('slug', 'our-story')->first()->path)->toBe('/about/our-story');
});

it('does not overwrite edited content on a re-run', function () {
    $this->seed(PageSeeder::class);

    $page = Page::where('slug', 'contact')->first();
    $page->update(['title' => 'Talk To Us']);

    $this->seed(PageSeeder::class);

    expect($page->fresh()->title)->toBe('Talk To Us')
        ->and(Page::where('slug', 'contact')->count())->toBe(1);
});
