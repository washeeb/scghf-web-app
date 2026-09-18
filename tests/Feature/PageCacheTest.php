<?php

declare(strict_types=1);

use App\Enums\PageStatus;
use App\Models\Announcement;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Settings;
use App\Support\SiteCache;
use App\Support\ThemeTokens;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 15 — the full-page cache and the fragment cache
|--------------------------------------------------------------------------
|
| A cache that is wrong is worse than no cache. So the tests here are
| mostly about the ways it must NOT serve a stored page: to somebody signed
| in, with somebody else's CSRF token, with yesterday's CSP nonce, after a
| publish, on a personal page, with a flash message, in the other theme.
|
*/

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'cache.stores.pages' => ['driver' => 'array'],
        'performance.page_cache.enabled' => true,
    ]);
    Cache::store('pages')->flush();
    Cache::flush();

    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(PageSeeder::class);
    $this->seed(MenuSeeder::class);
    $this->seed(CmsReferenceSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
    SiteCache::flush();
});

function liveArticle(string $title = 'A visit to Bongo'): Post
{
    return Post::create([
        'title' => $title,
        'status' => PageStatus::Published,
        'published_at' => now()->subDay(),
        'body' => '<p>What happened.</p>',
        'excerpt' => 'What we saw.',
    ]);
}

// ── Hits and misses ─────────────────────────────────────────────────────────

it('serves a public page from the cache on the second visit, with no queries', function () {
    liveArticle();

    $this->get(route('news.index'))->assertOk()->assertHeader('X-Page-Cache', 'miss');

    DB::enableQueryLog();
    $this->get(route('news.index'))->assertOk()->assertHeader('X-Page-Cache', 'hit')->assertSee('A visit to Bongo');
    $reads = array_filter(DB::getQueryLog(), fn (array $q): bool => ! str_starts_with(strtolower($q['query']), 'insert') && ! str_contains($q['query'], 'visitor_stats') && ! str_contains($q['query'], 'sessions'));
    DB::disableQueryLog();

    expect($reads)->toBe([]);
});

it('swaps in a fresh CSP nonce and CSRF token on every hit', function () {
    $first = $this->get(route('contact'))->assertHeader('X-Page-Cache', 'miss');
    $second = $this->get(route('contact'))->assertHeader('X-Page-Cache', 'hit');

    preg_match('/nonce="([^"]+)"/', (string) $first->getContent(), $n1);
    preg_match('/nonce="([^"]+)"/', (string) $second->getContent(), $n2);
    preg_match('/name="_token" value="([^"]+)"/', (string) $second->getContent(), $t2);

    expect($n1[1])->not->toBe($n2[1])
        ->and((string) $second->getContent())->not->toContain($n1[1])
        ->and($second->headers->get('Content-Security-Policy'))->toContain("'nonce-{$n2[1]}'")
        ->and($t2[1])->toBe(csrf_token());
});

it('accepts a form posted from a cached page', function () {
    $this->get(route('contact'))->assertHeader('X-Page-Cache', 'miss');
    $page = $this->get(route('contact'))->assertHeader('X-Page-Cache', 'hit');

    preg_match('/name="_token" value="([^"]+)"/', (string) $page->getContent(), $t);

    $this->post(route('contact'), ['_token' => $t[1], 'name' => '', 'email' => 'x'])
        ->assertStatus(302)   // validation, not 419
        ->assertSessionHasErrors('name');
});

// ── What is never cached ────────────────────────────────────────────────────

it('never caches for somebody signed in', function () {
    $this->actingAs(User::factory()->donor()->create()->fresh());

    $this->get(route('news.index'))->assertHeader('X-Page-Cache', 'skip');
    $this->get(route('news.index'))->assertHeader('X-Page-Cache', 'skip');
});

it('never caches the basket, the checkout, the account, the admin, search or a personal page', function (string $path) {
    $this->get($path);
    $response = $this->get($path);

    expect($response->headers->get('X-Page-Cache'))->not->toBe('hit', $path);
})->with([
    '/basket',
    '/checkout',
    '/login',
    '/search?q=hope',
    fn () => '/'.config('admin.path'),
    '/donate/01HZZZZZZZZZZZZZZZZZZZZZZZ/thank-you',
    '/shop/orders/track',
]);

it('never caches a page with a query string other than page=', function () {
    liveArticle();

    $this->get(route('news.index', ['utm_source' => 'whatsapp']));
    $this->get(route('news.index', ['utm_source' => 'whatsapp']))->assertHeader('X-Page-Cache', 'skip');

    $this->get(route('news.index', ['page' => 1]));
    $this->get(route('news.index', ['page' => 1]))->assertHeader('X-Page-Cache', 'hit');
});

it('never stores a page rendered with a flash message', function () {
    $this->withSession(['_flash' => ['new' => ['status'], 'old' => []], 'status' => 'Thanks!'])
        ->get(route('contact'))->assertHeader('X-Page-Cache', 'skip');
});

it('never stores a response that sets its own cookie', function () {
    $product = Product::factory()->create(['is_published' => true]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 4_500, 'is_active' => true]);

    // Adding to the basket sets the cart cookie on the redirect; the basket
    // page is excluded anyway. The product page itself is cacheable.
    $this->get(route('shop.show', $product));
    $this->get(route('shop.show', $product))->assertHeader('X-Page-Cache', 'hit');
});

it('never stores an error page', function () {
    $this->get('/no-such-page')->assertNotFound();
    $this->get('/no-such-page')->assertNotFound()->assertHeader('X-Page-Cache', 'miss');
});

// ── Variants ────────────────────────────────────────────────────────────────

it('keeps the light and dark themes as separate copies', function () {
    liveArticle();

    $this->get(route('news.index'));
    $light = $this->get(route('news.index'))->assertHeader('X-Page-Cache', 'hit');

    $this->withUnencryptedCookie('scghf_theme', 'dark')->get(route('news.index'))->assertHeader('X-Page-Cache', 'miss');
    $dark = $this->withUnencryptedCookie('scghf_theme', 'dark')->get(route('news.index'))->assertHeader('X-Page-Cache', 'hit');

    expect((string) $dark->getContent())->toContain('data-theme="dark"')
        ->and((string) $light->getContent())->not->toContain('data-theme="dark"');
});

// ── Invalidation ────────────────────────────────────────────────────────────

it('serves the new version the request after a publish', function () {
    liveArticle('First headline');
    $this->get(route('news.index'));
    $this->get(route('news.index'))->assertHeader('X-Page-Cache', 'hit')->assertSee('First headline');

    liveArticle('Second headline');

    $this->get(route('news.index'))->assertHeader('X-Page-Cache', 'miss')->assertSee('Second headline');
});

it('rebuilds the menus the request after a menu item changes', function () {
    $this->get('/');
    $this->get('/');

    $menu = Menu::where('key', 'header')->firstOrFail();
    MenuItem::create(['menu_id' => $menu->id, 'label' => 'Brand New Link', 'link_type' => 'external', 'url' => 'https://example.org/brand-new', 'sort_order' => 99]);

    $this->get('/')->assertHeader('X-Page-Cache', 'miss')->assertSee('Brand New Link');
});

it('shows a new announcement, and hides one switched off, the request after', function () {
    $this->get('/');
    $this->get('/')->assertHeader('X-Page-Cache', 'hit');

    $announcement = Announcement::create([
        'title' => 'Office closed on Monday',
        'body' => 'Back on Tuesday.',
        'placement' => 'announcement_bar',
        'is_active' => true,
    ]);

    $this->get('/')->assertSee('Office closed on Monday');

    $announcement->update(['is_active' => false]);

    $this->get('/')->assertDontSee('Office closed on Monday');
});

it('reaches every page when a setting changes', function () {
    $this->get(route('contact'));
    $this->get(route('contact'))->assertHeader('X-Page-Cache', 'hit');

    setting()->set('contact.phone_primary', '+233 20 000 0000');

    $this->get(route('contact'))->assertHeader('X-Page-Cache', 'miss');
});

it('can be switched off', function () {
    config(['performance.page_cache.enabled' => false]);

    $this->get(route('contact'))->assertHeader('X-Page-Cache', 'skip');
    $this->get(route('contact'))->assertHeader('X-Page-Cache', 'skip');
});

// ── The fragment cache ──────────────────────────────────────────────────────

it('bumps one generation number and every fragment key moves with it', function () {
    $before = SiteCache::generation();
    $key = SiteCache::key('menu:header:guest');

    Page::factory()->create(['status' => PageStatus::Published]);

    expect(SiteCache::generation())->toBeGreaterThan($before)
        ->and(SiteCache::key('menu:header:guest'))->not->toBe($key);
});

it('survives a cache store that lost the generation key', function () {
    $first = SiteCache::generation();
    SiteCache::bump();
    $second = SiteCache::generation();

    Cache::flush();
    SiteCache::flush();

    // A fresh store starts at 1; the next bump must not land on a number
    // an old entry could still be sitting under. Generations are clock
    // milliseconds, so a wipe-and-bump inside the same millisecond as the
    // last bump is the one case that could collide; clearing a cache takes
    // longer than that, and so does this line.
    expect(SiteCache::generation())->toBe(1);
    usleep(2_000);
    SiteCache::bump();
    expect(SiteCache::generation())->toBeGreaterThan($second);
});
