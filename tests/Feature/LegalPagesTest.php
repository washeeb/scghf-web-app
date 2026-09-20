<?php

declare(strict_types=1);

use App\Models\Page;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\BlockTypeSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PageContentSeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The legal pages
|--------------------------------------------------------------------------
|
| Nine locked policy pages have been seeded empty since Phase 3; the
| anti-fraud statement the Phase 6 brief asked for was not seeded at all.
| Each now has a first draft — written from what the application actually
| does — and every draft stays a DRAFT: a policy is the trustees' undertaking,
| and publishing it is their decision, not a seeder's.
|
| A CMS PAGE WITHOUT A HERO HAD NO H1 AND NO BREADCRUMB. A policy that is one
| rich-text block rendered with no heading at all.
|
*/

const LEGAL_SLUGS = [
    'privacy-policy', 'terms', 'donation-policy', 'refund-policy', 'shipping-and-delivery',
    'cookie-policy', 'safeguarding', 'accessibility', 'whistleblowing', 'anti-fraud',
];

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(BlockTypeSeeder::class);
    $this->seed(PageSeeder::class);
    $this->seed(PageContentSeeder::class);
    $this->seed(MenuSeeder::class);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

it('gives every policy page a draft, locked, unpublished, and flagged as a draft in its own text', function () {
    foreach (LEGAL_SLUGS as $slug) {
        $page = Page::where('slug', $slug)->whereNull('parent_id')->first();

        expect($page)->not->toBeNull($slug)
            ->and($page->is_locked)->toBeTrue($slug)
            ->and($page->isLive())->toBeFalse($slug)
            ->and($page->sections()->count())->toBeGreaterThan(0, $slug)
            ->and((string) $page->sections()->first()->field('body'))->toContain('Draft for the trustees to review');
    }
});

it('is not visible to the public until the trustees publish it', function () {
    $page = Page::where('slug', 'privacy-policy')->firstOrFail();

    $this->get($page->path)->assertNotFound();

    $page->publish();

    $this->get($page->path)
        ->assertOk()
        ->assertSee('Data Protection Act, 2012')
        ->assertSee('Paystack');
});

it('renders an h1, a breadcrumb and a last-updated date on a page with no hero block', function () {
    $page = Page::where('slug', 'refund-policy')->firstOrFail();
    $page->publish();

    $html = $this->get($page->path)->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('>Refund Policy</h1>')
        ->toContain('aria-label="Breadcrumb"')
        ->toContain('BreadcrumbList')
        ->toContain('Last updated');
});

it('walks the parent chain in the breadcrumb of a nested page', function () {
    $parent = Page::where('slug', 'get-involved')->firstOrFail();
    $child = Page::where('slug', 'donate-goods')->firstOrFail();
    $parent->publish();
    $child->publish();

    $html = $this->get($child->path)->assertOk()->getContent();

    expect($html)->toContain('href="'.url($parent->path).'"')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('does not add a second header to a page that opens with a hero', function () {
    $page = Page::where('slug', 'about')->firstOrFail();
    $page->sections()->create(['block_type' => 'hero', 'data' => ['heading' => 'About the Foundation, in a hero']]);
    $page->publish();

    $html = $this->get($page->path)->assertOk()->getContent();

    // The hero wraps its first word for the underline, so match on text.
    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(strip_tags((string) preg_replace('/\s+/', ' ', $html)))->toContain('About the Foundation, in a hero');
});

it('links the anti-fraud statement from the legal footer once published', function () {
    $page = Page::where('slug', 'anti-fraud')->firstOrFail();
    $page->publish();

    $this->get('/')->assertOk()->assertSee('href="'.$page->path.'"', escape: false)->assertSee('Anti-Fraud');
});

it('describes only cookies the site actually sets', function () {
    $body = (string) Page::where('slug', 'cookie-policy')->firstOrFail()->sections()->first()->field('body');

    expect($body)->toContain('Basket')
        ->toContain('Theme')
        ->toContain('Session cookie')
        ->not->toContain('Google Analytics');
});
