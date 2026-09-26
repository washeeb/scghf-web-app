<?php

declare(strict_types=1);

use App\Console\Commands\BrandAssets;
use App\Models\Faq;
use App\Models\Media;
use App\Models\Page;
use App\Support\Pwa;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LaunchContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;

/*
|--------------------------------------------------------------------------
| The visual template and the brand assets
|--------------------------------------------------------------------------
|
| The site takes its shape from the template the foundation chose and its
| logo from the files the foundation uploaded. These tests pin the parts a
| refactor could quietly lose: the logo in the header, the settings that
| point at it, the eyebrows and bands on the home page, and the seeder's
| promise not to overwrite an editor's words.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    app(Settings::class)->flush();
});

// ── The logo ─────────────────────────────────────────────────────────────────

it('imports the logo pack into the library once and points the header, icon and social-image settings at it', function () {
    $this->artisan('scghf:brand-assets')->assertSuccessful();

    $lockup = BrandAssets::asset('lockup-light');
    $dark = BrandAssets::asset('lockup-dark');
    $icon = BrandAssets::asset('icon');
    $card = BrandAssets::asset('social-card');

    expect($lockup)->not->toBeNull()
        ->and($dark)->not->toBeNull()
        ->and($icon)->not->toBeNull()
        ->and($card)->not->toBeNull()
        ->and($lockup->licence)->toBe(Media::LICENCE_OWN)
        ->and($lockup->isPublishable())->toBeTrue()
        // Provenance is a note, not a photographer's credit.
        ->and($dark->credit)->toBeNull()
        ->and($dark->getCustomProperty('note'))->toContain('Derived');

    app(Settings::class)->flush();
    expect((int) setting('header.logo_light'))->toBe($lockup->getKey())
        ->and((int) setting('header.logo_dark'))->toBe($dark->getKey())
        ->and((int) setting('header.logo_icon'))->toBe($icon->getKey())
        ->and((int) setting('seo.og_image'))->toBe($card->getKey());

    // Twice: nothing new in the library, nothing re-pointed.
    $count = Media::query()->count();
    $this->artisan('scghf:brand-assets')->assertSuccessful();
    expect(Media::query()->count())->toBe($count);
});

it('never overwrites a logo the foundation has chosen', function () {
    $own = Media::factory()->create(['alt_text' => 'Their own logo', 'metadata_stripped_at' => now()]);
    app(Settings::class)->set('header.logo_light', (string) $own->getKey());
    app(Settings::class)->flush();

    $this->artisan('scghf:brand-assets')->assertSuccessful();
    app(Settings::class)->flush();

    expect((int) setting('header.logo_light'))->toBe($own->getKey())
        ->and((int) setting('header.logo_dark'))->toBe(BrandAssets::asset('lockup-dark')->getKey());
});

it('shows the uploaded logo in the header, both variants, and uses the square icon for the app', function () {
    $this->artisan('scghf:brand-assets')->assertSuccessful();
    app(Settings::class)->flush();

    $light = BrandAssets::asset('lockup-light');
    $dark = BrandAssets::asset('lockup-dark');

    $this->get('/')->assertOk()
        ->assertSee('alt="'.e($light->alt_text).'"', false)
        ->assertSee($light->conversionUrl('thumb'), false)
        ->assertSee($dark->conversionUrl('thumb'), false)
        ->assertSee('sizes="220px"', false)
        // The wordmark stays as the accessible name of the home link.
        ->assertSee('<span class="sr-only">', false);

    // The PWA icon version follows the square icon, not the lockup.
    $withIcon = app(Pwa::class)->iconVersion();
    app(Settings::class)->set('header.logo_icon', null);
    app(Settings::class)->flush();
    expect(app(Pwa::class)->iconVersion())->not->toBe($withIcon);
});

// ── The template ─────────────────────────────────────────────────────────────

it('renders the home page in the template: serif display headline with an underlined first word, eyebrows, pill buttons, brand bands', function () {
    $this->seed(LaunchContentSeeder::class);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<span class="underline decoration-[var(--brand-secondary)]')
        ->toContain('class="eyebrow"')
        ->toContain('class="eyebrow eyebrow-on-dark"')
        ->toContain('btn btn-accent')
        ->toContain('btn btn-sm btn-brand')
        ->toContain('Where we are going')
        // The impact band and the donation band sit on the brand colour.
        ->toContain('bg-[var(--brand-primary)] text-[var(--text-on-brand)]');

    // Block order on a fresh seed: the donation band follows the appeals.
    $types = Page::query()->where('slug', 'home')->first()->sections()->orderBy('sort_order')->pluck('block_type')->all();
    expect(array_search('donation-widget', $types, true))->toBe(array_search('featured-causes', $types, true) + 1)
        ->and($types)->toContain('faq');
});

it('brings a home page seeded before the restyle up to the arrangement without touching an editor\'s words', function () {
    $this->seed(LaunchContentSeeder::class);
    $home = Page::query()->where('slug', 'home')->first();

    // Undo the restyle by hand: strip eyebrows and settings, drop the new bands, edit a heading.
    $home->sections()->whereIn('block_type', ['donation-widget', 'faq'])->delete();
    foreach ($home->sections()->get() as $section) {
        $data = $section->data;
        unset($data['eyebrow']);
        if ($section->block_type === 'divisions') {
            $data['heading'] = 'An editor wrote this';
        }
        $section->forceFill(['data' => $data, 'settings' => null])->save();
    }

    $this->seed(LaunchContentSeeder::class);
    $home->refresh();

    $divisions = $home->sections()->where('block_type', 'divisions')->first();
    $stats = $home->sections()->where('block_type', 'impact-stats')->first();

    expect($divisions->data['heading'])->toBe('An editor wrote this')
        ->and($divisions->data['eyebrow'])->toBe('How we help')
        ->and($stats->settings['background'] ?? null)->toBe('brand')
        ->and($home->sections()->where('block_type', 'donation-widget')->count())->toBe(1)
        ->and($home->sections()->where('block_type', 'faq')->count())->toBe(1);

    // The sort order is still a clean sequence.
    $orders = $home->sections()->orderBy('sort_order')->pluck('sort_order')->all();
    expect($orders)->toBe(range(0, count($orders) - 1));
});

it('draws the CTA band as a photograph band when told to, and the FAQ with its side picture', function () {
    $photo = Media::factory()->create(['alt_text' => 'A shoreline', 'metadata_stripped_at' => now(), 'licence' => Media::LICENCE_OWN]);
    $page = Page::query()->where('slug', 'home')->first();
    $page->sections()->delete();
    $page->sections()->create(['block_type' => 'cta-band', 'sort_order' => 0, 'data' => ['heading' => 'Give hope today', 'cta_label' => 'Donate', 'cta_url' => '/donate', 'background' => 'image', 'image' => $photo->getKey()]]);
    $page->sections()->create(['block_type' => 'faq', 'sort_order' => 1, 'data' => ['heading' => 'Questions', 'image' => $photo->getKey()]]);
    Faq::query()->create(['question' => 'Is this a question?', 'answer' => '<p>It is.</p>', 'is_published' => true]);
    $page->publish();

    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, $photo->conversionUrl('hero')))->toBeGreaterThanOrEqual(1)
        ->and($html)->toContain('bg-black/60')
        ->and(substr_count($html, $photo->conversionUrl('card')))->toBeGreaterThanOrEqual(1);
});

it('puts a card photograph\'s credit in the title attribute rather than a caption under it', function () {
    $photo = Media::factory()->create(['alt_text' => 'A clinic', 'credit' => 'Ama Mensah', 'metadata_stripped_at' => now(), 'licence' => Media::LICENCE_STOCK, 'licence_url' => 'https://unsplash.com/photos/x']);

    $card = view('components.media.image', ['media' => $photo, 'credit' => 'title', 'size' => 'card', 'eager' => false, 'sizes' => null, 'attributes' => new ComponentAttributeBag, 'slot' => new HtmlString])->render();
    $caption = view('components.media.image', ['media' => $photo, 'credit' => 'caption', 'size' => 'card', 'eager' => false, 'sizes' => null, 'attributes' => new ComponentAttributeBag, 'slot' => new HtmlString])->render();

    expect($card)->toContain('title="Photo: Ama Mensah"')->not->toContain('<figcaption')
        ->and($caption)->toContain('<figcaption')->not->toContain('title="Photo');
});
