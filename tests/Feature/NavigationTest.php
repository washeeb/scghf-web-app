<?php

declare(strict_types=1);

use App\Enums\MenuItemLinkType;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The header navigation, at both widths
|--------------------------------------------------------------------------
|
| ONE MENU, TWO RENDERINGS. A row of dropdowns at `md` and above, an expanding
| panel below it. What must never differ is what they CONTAIN — a site that
| shows more of itself on a phone than on a laptop, or the reverse, has a
| navigation nobody can reason about.
|
| IT WORKS WITH NO JAVASCRIPT. Every menu here is a `<details>`, because the
| browser already implements disclosure: announced state, keyboard operation,
| no script. `navigation.js` adds Escape and click-away, and every one of those
| is absent rather than broken if it never loads — which on a data-saver proxy
| that rewrites scripts is a real state, not a hypothetical.
|
| THE CHILDREN WERE UNREACHABLE. The seeded header has ten items under "About"
| and "Get Involved", `max_depth 1`, and a description promising "one level of
| dropdown". Nothing rendered them, at any width. Pages that existed, were
| published, and could not be got to.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(PageSeeder::class);
    $this->seed(MenuSeeder::class);

    /*
     * The seeder creates every page as a DRAFT, which is right — content is
     * written before it is published — and means `isRenderable()` correctly
     * drops every page-backed item until somebody publishes it. A navigation
     * test against an empty navigation asserts nothing, so the pages are
     * published here.
     *
     * The route-backed items (`divisions.index`, `shop.index` and the rest)
     * stay dropped, because those routes genuinely do not exist yet. That is
     * the behaviour under test in "it links only to destinations that exist".
     */
    Page::all()->each->publish();
});

// ── The gap this closed ─────────────────────────────────────────────────────

it('renders the child items the seeded menu has always contained', function () {
    /*
     * The regression that matters. These are seeded, published, and were
     * reachable from nowhere in the header.
     */
    $response = $this->get('/');

    foreach (['Our Story', 'Vision & Mission', 'Leadership', 'Transparency'] as $label) {
        // No `escape: false` — "Vision & Mission" is rendered as `Vision &amp;
        // Mission`, and assertSee escapes the needle the same way.
        $response->assertSee($label);
    }
});

it('renders every renderable child of every top-level item', function () {
    // Asserted against the database rather than a list typed here, so adding a
    // child in the CMS cannot quietly go unrendered.
    $html = $this->get('/')->getContent();

    $children = Menu::renderable('header')
        ->flatMap(fn (MenuItem $item) => $item->children)
        ->filter(fn (MenuItem $child) => $child->isRenderable());

    expect($children)->not->toBeEmpty();

    foreach ($children as $child) {
        // `e()`, because a label containing & reaches the page as &amp;.
        expect($html)->toContain(e($child->label));
    }
});

it('keeps the parent page reachable from inside its own dropdown', function () {
    /*
     * "About" is a page AND a group of pages. Turning it into a pure toggle
     * would orphan /about — published, linked from nowhere. So it appears as
     * the first entry inside its own dropdown.
     */
    $about = Menu::renderable('header')->first(fn (MenuItem $item) => $item->children->isNotEmpty());

    expect($about)->not->toBeNull()
        ->and($about->resolveUrl())->not->toBeNull();

    $html = $this->get('/')->getContent();

    // Once as the dropdown's own label, and again as the link inside it.
    expect(substr_count($html, 'href="'.$about->resolveUrl().'"'))->toBeGreaterThanOrEqual(2);
});

// ── Both widths carry the same links ────────────────────────────────────────

it('offers the same destinations on a phone as on a laptop', function () {
    /*
     * The two renderings are separate markup. This is what stops them drifting:
     * every URL in one has to be in the other, and the only way to satisfy it
     * is to feed both from the same menu.
     */
    $html = $this->get('/')->getContent();

    [, $desktop] = explode('md:flex', $html, 2);
    [$desktopNav] = explode('</ul>', $desktop, 2);

    [, $mobile] = explode('data-mobile-nav', $html, 2);

    preg_match_all('/href="([^"]+)"/', $desktopNav, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $url) {
        expect($mobile)->toContain('href="'.$url.'"');
    }
});

// ── It works without JavaScript ─────────────────────────────────────────────

it('builds every menu from details elements rather than buttons', function () {
    /*
     * A `<button>` here would need JavaScript to do anything, plus aria-expanded
     * and aria-controls maintained by hand. `<details>` is announced, keyboard
     * operable and functional with no script — which is the difference between
     * a site a data-saver proxy can navigate and one it cannot.
     */
    $html = $this->get('/')->getContent();

    expect($html)->toContain('<details')
        ->and($html)->toContain('data-mobile-nav')
        ->and($html)->toContain('data-nav-dropdown')
        ->and($html)->toContain('<summary');
});

it('never renders a link that goes nowhere', function () {
    // `href="#"` is a trap: it looks like a link to a keyboard user, takes
    // focus, and does nothing.
    $this->get('/')->assertDontSee('href="#"', escape: false);
});

it('starts closed', function () {
    // A panel rendered open would cover the page on first paint and then
    // collapse, which is the same class of failure as the theme flash.
    $html = $this->get('/')->getContent();

    expect($html)->not->toContain('data-mobile-nav open')
        ->and($html)->not->toContain('<details open');
});

// ── What stays out of the menu ──────────────────────────────────────────────

it('keeps the donate button out of the hamburger', function () {
    /*
     * The single most important control on the site. Behind a hamburger it is
     * one tap further away on exactly the device most of this foundation's
     * donors use — so it sits in the header at every width, and the panel is
     * rendered after it.
     */
    $donate = highlightDonateAt('donation-faq');

    [$beforePanel] = explode('data-mobile-nav', $this->get('/')->getContent(), 2);

    expect($beforePanel)->toContain($donate->label);
});

it('does not put the highlighted item in the list as well as on the button', function () {
    // Showing it in both would put the same link on the page twice, one after
    // the other.
    $donate = highlightDonateAt('donation-faq');

    $html = $this->get('/')->getContent();

    expect(substr_count($html, 'href="'.$donate->resolveUrl().'"'))->toBe(1);
});

it('still renders a working donate button when nobody has highlighted one', function () {
    /*
     * A donation site whose donate button disappears because somebody removed
     * the highlighted item — or, as happened until Phase 6, because the seeder
     * named a route that was never built — would be the worst possible failure
     * of this fallback. The highlight is removed here to exercise it.
     */
    MenuItem::where('is_highlighted', true)->delete();

    expect(Menu::renderable('header')->firstWhere('is_highlighted', true))->toBeNull();

    $this->get('/')
        ->assertOk()
        ->assertSee(__('Donate'))
        ->assertSee(url('/donate'), escape: false);
});

// ── It links only to destinations that exist ────────────────────────────────

it('drops an item whose route has not been built yet', function () {
    /*
     * A route-type item naming a route that does not exist is dropped rather
     * than rendered — the navigation must not link into a 404 of the site's
     * own making. Until Phase 6 the seeder itself did this to the Donate pill,
     * the Impact link and the Divisions menu by naming routes that were never
     * given those names.
     */
    $item = MenuItem::where('route_name', 'shop.index')->firstOrFail();
    $item->forceFill(['route_name' => 'shop.not-built-yet'])->save();

    $html = $this->get('/')->getContent();

    expect(Route::has('shop.not-built-yet'))->toBeFalse()
        ->and($html)->not->toContain('shop.not-built-yet')
        ->and(Menu::renderable('header')->pluck('label'))->not->toContain('Shop');
});

/**
 * Point the seeded highlight at a page that actually resolves.
 *
 * The seeder aims it at `donate.index`, which arrives in a later phase — so
 * without this the highlighted item is dropped and there is nothing to assert
 * about where it renders.
 */
function highlightDonateAt(string $slug): MenuItem
{
    $item = MenuItem::where('is_highlighted', true)->firstOrFail();

    $item->forceFill([
        'link_type' => MenuItemLinkType::Page,
        'route_name' => null,
        'page_id' => Page::where('slug', $slug)->firstOrFail()->getKey(),
    ])->save();

    return $item->refresh();
}

// ── Signing in ──────────────────────────────────────────────────────────────

it('puts the account link inside the panel on a phone', function () {
    [, $panel] = explode('data-mobile-nav', $this->get('/')->getContent(), 2);

    expect($panel)->toContain(__('Sign in'));
});

it('offers the account rather than sign-in once signed in', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/')->assertSee(__('Your account'));
});

// ── It does not fall over ───────────────────────────────────────────────────

it('renders a header with no navigation rather than a 500', function () {
    /*
     * A fresh environment where the seeder has not run. A bare header is
     * visibly wrong and somebody fixes it; a site that will not load is an
     * outage.
     */
    MenuItem::query()->delete();
    Menu::query()->delete();

    $this->get('/')->assertOk();
});

it('drops a child whose destination is no longer published', function () {
    /*
     * `isRenderable()` is what stops the navigation linking into a 404 of the
     * site's own making — and it has to be applied to CHILDREN too, which is
     * the easy half to forget when rendering a second level.
     *
     * Unpublishing rather than deleting, because that is the case that actually
     * happens: somebody withdraws a page and the links to it have to go with
     * it, the same day.
     *
     * The page is re-fetched rather than reached through `$child->page`. The
     * menu tree deliberately selects only the four columns it renders, so the
     * related model on it is partial — fine for reading a title, wrong for
     * saving.
     */
    $parent = Menu::renderable('header')->first(fn (MenuItem $item) => $item->children->isNotEmpty());
    $child = $parent->children->first(fn (MenuItem $item) => $item->page_id !== null);

    expect($child)->not->toBeNull();

    Page::findOrFail($child->page_id)->unpublish();

    expect($this->get('/')->getContent())->not->toContain('>'.e($child->label).'</a>');
});
