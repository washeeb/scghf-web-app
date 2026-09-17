<?php

declare(strict_types=1);

use App\Enums\PageStatus;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\BlogCategory;
use App\Models\Faq;
use App\Models\Post;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 13 — search, sharing, sitemaps, attribution
|--------------------------------------------------------------------------
|
| `seo_meta` has been polymorphic since Phase 3 and only the Page form
| reached it, with three of eleven columns — and PageMeta ignored the
| Open Graph title, the canonical and nofollow even where they were set.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(CmsReferenceSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function seoSetting(string $key, string $value): void
{
    [$group, $name] = explode('.', $key, 2);
    Setting::query()->where('group', $group)->where('key', $name)->update(['value' => $value]);
    app(Settings::class)->flush();
}

function livePost(array $attributes = []): Post
{
    return Post::create(array_merge([
        'title' => 'A visit to Bongo', 'slug' => 'a-visit-to-bongo', 'status' => PageStatus::Published,
        'published_at' => now()->subDay(), 'body' => '<p>What happened.</p>', 'excerpt' => 'What we saw.',
    ], $attributes));
}

// ── The fields, and whether they reach the page ────────────────────────────

it('prints the search, share, canonical and robots overrides an editor sets', function () {
    seoSetting('seo.allow_indexing', '1');
    $post = livePost();
    $post->seo()->create([
        'title' => 'Bongo trip — the short version',
        'description' => 'Three days in the Upper East.',
        'og_title' => 'We went to Bongo',
        'og_description' => 'Share this one.',
        'canonical_url' => 'https://example.org/original-story',
        'no_follow' => true,
        'twitter_card' => 'summary',
    ]);

    $this->get(route('news.show', $post))
        ->assertOk()
        ->assertSee('<title>Bongo trip — the short version', escape: false)
        ->assertSee('<meta name="description" content="Three days in the Upper East.">', escape: false)
        ->assertSee('<meta property="og:title" content="We went to Bongo">', escape: false)
        ->assertSee('<meta property="og:description" content="Share this one.">', escape: false)
        ->assertSee('<link rel="canonical" href="https://example.org/original-story">', escape: false)
        ->assertSee('<meta name="robots" content="index, nofollow">', escape: false)
        ->assertSee('<meta name="twitter:card" content="summary">', escape: false);
});

it('offers the search and sharing fields on a post, with character counts', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();
    $editor = User::factory()->staff()->withTwoFactor()->create()->fresh();
    $editor->givePermissionTo(['blog.view', 'blog.create', 'blog.update', 'blog.publish', 'media.view']);
    $this->actingAs($editor);
    $post = livePost();

    Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
        ->assertOk()
        ->assertSee('Search & sharing')
        ->fillForm(['seo.title' => str_repeat('x', 70), 'seo.og_title' => 'Shared'])
        ->assertSee('10 over the 60')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($post->fresh()->seo->og_title)->toBe('Shared');
});

it('gives categories their own search and sharing fields', function () {
    seoSetting('seo.allow_indexing', '1');
    $category = BlogCategory::create(['name' => 'Field notes', 'slug' => 'field-notes', 'is_published' => true, 'description' => 'From the road.']);
    $category->seo()->create(['title' => 'Field notes from the north']);

    $this->get(route('news.category', $category))->assertOk()->assertSee('<title>Field notes from the north', escape: false);
});

// ── Structured data ─────────────────────────────────────────────────────────

it('emits Article, Product with a GHS offer, FAQPage and DonateAction as JSON-LD', function () {
    seoSetting('seo.allow_indexing', '1');
    $post = livePost();
    $this->get(route('news.show', $post))->assertSee('"@type":"Article"', escape: false)->assertSee('"headline":"A visit to Bongo"', escape: false);

    $product = Product::factory()->create(['name' => 'Tote bag', 'slug' => 'tote-bag', 'is_published' => true]);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 4_500, 'stock_on_hand' => 3, 'is_active' => true]);
    $this->get(route('shop.show', $product))
        ->assertOk()
        ->assertSee('"@type":"Product"', escape: false)
        ->assertSee('"priceCurrency":"GHS"', escape: false)
        ->assertSee('"price":"45.00"', escape: false)
        ->assertSee('schema.org/InStock', escape: false);

    Faq::create(['question' => 'Is my gift tax deductible?', 'answer' => '<p>It depends on <b>what</b> it funds.</p>', 'is_published' => true, 'sort_order' => 1]);
    $this->get(route('faq'))->assertOk()->assertSee('"@type":"FAQPage"', escape: false)->assertSee('"text":"It depends on what it funds."', escape: false);

    $this->get(route('donate'))->assertOk()->assertSee('"@type":"DonateAction"', escape: false)->assertSee('"priceCurrency":"GHS"', escape: false);
});

// ── Sitemaps and robots ─────────────────────────────────────────────────────

it('serves a sitemap index with per-type sitemaps that forget themselves on publish', function () {
    seoSetting('seo.allow_indexing', '1');
    livePost();

    $this->get('/sitemap.xml')->assertOk()->assertSee(route('sitemap.type', ['type' => 'posts']));
    $this->get('/sitemaps/posts.xml')->assertOk()->assertSee('a-visit-to-bongo')->assertDontSee('second-story');
    $this->get('/sitemaps/nope.xml')->assertNotFound();

    livePost(['title' => 'Second story', 'slug' => 'second-story']);

    // The cache was forgotten by the observer, so the new post is listed at once.
    $this->get('/sitemaps/posts.xml')->assertOk()->assertSee('second-story');
});

it('adds the extra robots.txt lines from the CMS, only when indexing is on', function () {
    seoSetting('seo.robots_extra', "Disallow: /old-section/\nCrawl-delay: 5");
    $this->get('/robots.txt')->assertOk()->assertDontSee('old-section');

    seoSetting('seo.allow_indexing', '1');
    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /old-section/')->assertSee('Crawl-delay: 5')->assertSee('Sitemap: ');
});

it('keeps only the page number in a paginated list’s canonical', function () {
    seoSetting('seo.allow_indexing', '1');
    livePost();

    $this->get(route('news.index', ['page' => 2, 'utm_source' => 'x', 'sort' => 'old']))
        ->assertSee('<link rel="canonical" href="'.route('news.index').'?page=2">', escape: false);
    $this->get(route('news.index', ['utm_source' => 'x']))
        ->assertSee('<link rel="canonical" href="'.route('news.index').'">', escape: false);
});

// ── Attribution ─────────────────────────────────────────────────────────────

it('remembers the campaign a visit arrived with and stamps it on the donation made later', function () {
    $this->seed(MessageTemplateSeeder::class);
    seoSetting('seo.allow_indexing', '1');
    livePost();

    // First touch: a WhatsApp link to a story.
    $this->get(route('news.show', 'a-visit-to-bongo').'?utm_source=whatsapp&utm_campaign=harvest&utm_medium=social')->assertOk();
    // A later link with different parameters does not overwrite it.
    $this->get('/?utm_source=facebook')->assertOk();

    expect(session('attribution.utm_source'))->toBe('whatsapp');

    // The donate page carries it as hidden fields.
    $this->get(route('donate'))->assertOk()->assertSee('name="utm_campaign" value="harvest"', escape: false);
});
