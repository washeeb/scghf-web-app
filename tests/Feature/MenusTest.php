<?php

declare(strict_types=1);

use App\Enums\MenuItemLinkType;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ── Link resolution ──────────────────────────────────────────────────────────

it('follows a page when its slug changes', function () {
    // The reason a page link is a relation and not a stored URL. A hardcoded
    // URL is how navigation quietly rots.
    $page = Page::create(['title' => 'About', 'slug' => 'about']);
    $menu = Menu::create(['key' => 'test', 'name' => 'Test']);

    $item = MenuItem::create([
        'menu_id' => $menu->id,
        'label' => 'About',
        'link_type' => MenuItemLinkType::Page,
        'page_id' => $page->id,
    ]);

    expect($item->resolveUrl())->toBe('/about');

    $page->update(['slug' => 'who-we-are']);

    expect($item->fresh()->resolveUrl())->toBe('/who-we-are');
});

it('omits a link whose page is unpublished', function () {
    $page = Page::create(['title' => 'Draft', 'slug' => 'draft']);
    $menu = Menu::create(['key' => 'test', 'name' => 'Test']);

    $item = MenuItem::create([
        'menu_id' => $menu->id, 'label' => 'Draft',
        'link_type' => MenuItemLinkType::Page, 'page_id' => $page->id,
    ]);

    // A visitor hitting a 404 from the site's own navigation is worse than a
    // missing link.
    expect($item->isRenderable())->toBeFalse();

    $page->publish();

    expect($item->fresh()->isRenderable())->toBeTrue();
});

it('omits a route link until that route exists', function () {
    $menu = Menu::create(['key' => 'test', 'name' => 'Test']);

    $item = MenuItem::create([
        'menu_id' => $menu->id, 'label' => 'Donate',
        'link_type' => MenuItemLinkType::Route, 'route_name' => 'donate.index',
    ]);

    // /donate arrives in Phase 8. Until then the nav is correct by omission
    // rather than showing a link that 404s.
    expect($item->resolveUrl())->toBeNull()
        ->and($item->isRenderable())->toBeFalse();
});

it('opens an external link in a new tab by default', function () {
    $menu = Menu::create(['key' => 'test', 'name' => 'Test']);

    $item = MenuItem::create([
        'menu_id' => $menu->id, 'label' => 'Paystack',
        'link_type' => MenuItemLinkType::External, 'url' => 'https://paystack.com',
    ]);

    expect($item->opens_in_new_tab)->toBeTrue()
        ->and($item->resolveUrl())->toBe('https://paystack.com');
});

it('hides a heading that has no children', function () {
    $menu = Menu::create(['key' => 'test', 'name' => 'Test', 'max_depth' => 1]);

    $heading = MenuItem::create([
        'menu_id' => $menu->id, 'label' => 'More', 'link_type' => MenuItemLinkType::Heading,
    ]);

    expect($heading->load('children')->isRenderable())->toBeFalse();

    $page = Page::create(['title' => 'X', 'slug' => 'x']);
    $page->publish();
    MenuItem::create([
        'menu_id' => $menu->id, 'parent_id' => $heading->id, 'label' => 'X',
        'link_type' => MenuItemLinkType::Page, 'page_id' => $page->id,
    ]);

    expect($heading->load('children')->isRenderable())->toBeTrue();
});

// ── Nesting rules ────────────────────────────────────────────────────────────

it('refuses nesting deeper than the layout can render', function () {
    $menu = Menu::create(['key' => 'test', 'name' => 'Test', 'max_depth' => 1]);

    $a = MenuItem::create(['menu_id' => $menu->id, 'label' => 'A', 'link_type' => MenuItemLinkType::Heading]);
    $b = MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $a->id, 'label' => 'B', 'link_type' => MenuItemLinkType::Heading]);

    // A third level would be saved and then silently never rendered — the worst
    // kind of bug, because the admin sees it save.
    MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $b->id, 'label' => 'C', 'link_type' => MenuItemLinkType::Heading]);
})->throws(RuntimeException::class);

it('refuses to make an item its own ancestor', function () {
    $menu = Menu::create(['key' => 'test', 'name' => 'Test', 'max_depth' => 3]);

    $a = MenuItem::create(['menu_id' => $menu->id, 'label' => 'A', 'link_type' => MenuItemLinkType::Heading]);
    $b = MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $a->id, 'label' => 'B', 'link_type' => MenuItemLinkType::Heading]);

    $a->update(['parent_id' => $b->id]);
})->throws(RuntimeException::class);

// ── Tree building ────────────────────────────────────────────────────────────

it('builds the whole tree in a single query', function () {
    $this->seed([PageSeeder::class, MenuSeeder::class]);
    $menu = Menu::where('key', 'header')->first();

    DB::enableQueryLog();
    $tree = $menu->tree();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Eager-loading children.children issues a query per level. The header
    // renders on every page, so this is the hot path.
    expect($tree)->not->toBeEmpty()
        ->and($queries)->toBeLessThanOrEqual(2);   // items + the page relation
});

it('filters the tree by auth state', function () {
    $menu = Menu::create(['key' => 'test', 'name' => 'Test']);
    $page = Page::create(['title' => 'X', 'slug' => 'x']);
    $page->publish();

    foreach (['all', 'guest', 'auth'] as $audience) {
        MenuItem::create([
            'menu_id' => $menu->id, 'label' => $audience, 'visible_to' => $audience,
            'link_type' => MenuItemLinkType::Page, 'page_id' => $page->id,
        ]);
    }

    expect($menu->tree(authenticated: false)->pluck('label')->all())->toBe(['all', 'guest'])
        ->and($menu->tree(authenticated: true)->pluck('label')->all())->toBe(['all', 'auth']);
});

it('nests children under their parent in the tree', function () {
    $this->seed([PageSeeder::class, MenuSeeder::class]);

    $about = Menu::where('key', 'header')->first()->tree()
        ->firstWhere('label', 'About');

    expect($about->children)->not->toBeEmpty()
        ->and($about->children->pluck('label')->all())->toContain('Our Story', 'Leadership');
});

// ── Locked menus ─────────────────────────────────────────────────────────────

it('refuses to delete a menu the layout depends on', function () {
    $this->seed([PageSeeder::class, MenuSeeder::class]);

    Menu::where('key', 'header')->first()->delete();
})->throws(RuntimeException::class);

it('takes child items with a deleted parent', function () {
    $menu = Menu::create(['key' => 'test', 'name' => 'Test']);
    $parent = MenuItem::create(['menu_id' => $menu->id, 'label' => 'P', 'link_type' => MenuItemLinkType::Heading]);
    MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $parent->id, 'label' => 'C', 'link_type' => MenuItemLinkType::Heading]);

    $parent->delete();

    // An orphaned submenu item would vanish from the nav with no clue why.
    expect(MenuItem::count())->toBe(0);
});

// ── Seeder ───────────────────────────────────────────────────────────────────

it('seeds the header with a highlighted donate call to action', function () {
    $this->seed([PageSeeder::class, MenuSeeder::class]);

    $donate = MenuItem::where('label', 'Donate')->where('is_highlighted', true)->first();

    expect($donate)->not->toBeNull()
        ->and($donate->link_type)->toBe(MenuItemLinkType::Route);
});

it('does not undo an editor arrangement on a re-run', function () {
    $this->seed([PageSeeder::class, MenuSeeder::class]);

    $menu = Menu::where('key', 'header')->first();
    $before = $menu->items()->count();
    $menu->items()->first()->update(['label' => 'Renamed by staff']);

    $this->seed(MenuSeeder::class);

    expect($menu->items()->count())->toBe($before)
        ->and(MenuItem::where('label', 'Renamed by staff')->exists())->toBeTrue();
});
