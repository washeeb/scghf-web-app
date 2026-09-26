<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Page;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| The hero, as one slide or several
|--------------------------------------------------------------------------
|
| The properties worth holding still: a hero with no slides is exactly the
| hero it was before slides existed; a hero with slides has one h1 rather
| than four; only the first picture is eager; and the mobile crop — which
| the resolver supplied as `image_mobile` and the view read as `$imageMobile`
| for its whole life, so it never once rendered — actually renders.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

function publishableImage(string $name): Media
{
    return Media::factory()->create([
        'name' => $name,
        'alt_text' => 'A photograph of '.$name,
        'metadata_stripped_at' => now(),
        'sanitisation_error' => null,
        'mime_type' => 'image/jpeg',
    ]);
}

function heroPage(array $data): Page
{
    $page = Page::factory()->create(['slug' => 'hero-test', 'title' => 'Hero test']);
    $page->publish();
    $page->sections()->create(['block_type' => 'hero', 'data' => $data, 'sort_order' => 0]);

    return $page->refresh();
}

it('draws a hero with no slides exactly as it always did — no carousel, no controls', function () {
    $image = publishableImage('still-hero');

    heroPage([
        'heading' => 'Turning remembrance into impact',
        'subheading' => 'Hope, healing and education.',
        'image' => $image->getKey(),
        'primary_cta_label' => 'Give today',
        'primary_cta_url' => '/donate',
    ]);

    $this->get('/hero-test')
        ->assertOk()
        // The heading is split so the first word can take the underline.
        ->assertSeeText('Turning remembrance into impact')
        ->assertDontSee('data-hero-slider', false)
        ->assertDontSee('Next slide')
        // The first word still takes the accent underline.
        ->assertSee('>Turning</span>', false);
});

it('becomes a carousel when slides are added, with one h1 and controls a keyboard can reach', function () {
    $first = publishableImage('slide-one');
    $second = publishableImage('slide-two');

    heroPage([
        'heading' => 'Turning remembrance into impact',
        'image' => $first->getKey(),
        'slides' => [
            ['heading' => 'A desk, a book, a chance', 'image' => $second->getKey(), 'eyebrow' => 'Education'],
        ],
    ]);

    $html = $this->get('/hero-test')->assertOk()->getContent();

    // `data-hero-slide` is a prefix of `data-hero-slider`; count the panels
    // by the role each one announces instead.
    expect(substr_count($html, 'aria-roledescription="slide"'))->toBe(2)
        // One page, one h1: the following slides are prose at the same size.
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('data-hero-slider')
        ->and($html)->toContain('Previous slide')
        ->and($html)->toContain('Pause the slideshow')
        ->and($html)->toContain('aria-roledescription="carousel"')
        // Off-screen slides are inert, so a keyboard cannot tab into a
        // button nobody can see.
        ->and($html)->toContain('inert')
        ->and($html)->toContain('A desk, a book, a chance')
        ->and($html)->toContain('Education')
        /*
         * Every slide's headline is set in the same face. The first one is
         * the page's h1, which takes the serif from app.css; the rest are
         * prose and have to ask for it by name — with the class that exists
         * (`.font-display`), not a Tailwind arbitrary value that generates
         * nothing and leaves slide two in the body sans.
         */
        ->and($html)->toContain('<p class="font-display mt-3 text-4xl');
});

it('lets the picture fill the band, with no strip of page showing above or below', function () {
    $image = publishableImage('wide');

    $page = heroPage(['heading' => 'First', 'image' => $image->getKey()]);

    /*
     * The generic section padding an editor can set (Presentation → spacing)
     * does not apply to a hero carrying a picture: the photograph fills the
     * whole band, and the padding would draw the page background above and
     * below it. Before slides existed the `<img>` was absolute on the section
     * and simply covered that padding; now it lives inside a slide, so the
     * padding has to actually go.
     */
    $section = $page->sections()->firstOrFail();
    $section->settings = ['padding' => 'large'];
    $section->save();

    $html = $this->get('/hero-test')->assertOk()->getContent();

    expect($html)->not->toContain('py-16 sm:py-24');

    // A hero with no picture is an ordinary band and keeps its spacing.
    $section->data = ['heading' => 'First'];
    $section->save();

    expect($this->get('/hero-test')->getContent())->toContain('py-16 sm:py-24');
});

it('is eager about the first picture and lazy about the rest', function () {
    $first = publishableImage('slide-one');
    $second = publishableImage('slide-two');

    heroPage([
        'heading' => 'First',
        'image' => $first->getKey(),
        'slides' => [['heading' => 'Second', 'image' => $second->getKey()]],
    ]);

    $html = $this->get('/hero-test')->assertOk()->getContent();

    expect(substr_count($html, 'loading="eager"'))->toBe(1)
        ->and(substr_count($html, 'loading="lazy"'))->toBe(1)
        // And only the first is preloaded in <head>.
        ->and(substr_count($html, 'rel="preload" as="image"'))->toBe(1);
});

it('renders the art-directed mobile crop it was silently dropping', function () {
    $desktop = publishableImage('wide');
    $mobile = publishableImage('narrow');

    heroPage([
        'heading' => 'First',
        'image' => $desktop->getKey(),
        'image_mobile' => $mobile->getKey(),
    ]);

    $html = $this->get('/hero-test')->assertOk()->getContent();

    expect($html)->toContain('<source media="(max-width: 767px)"')
        ->and($html)->toContain('media="(max-width: 767px)" fetchpriority="high"');
});

it('stands shorter by default, and taller only when an editor asks', function () {
    $image = publishableImage('wide');

    $page = heroPage(['heading' => 'First', 'image' => $image->getKey()]);

    // No choice made: the compact band.
    expect($this->get('/hero-test')->getContent())->toContain('pb-20');

    $section = $page->sections()->firstOrFail();
    $section->data = $section->data + ['height' => 'tall'];
    $section->save();

    expect($this->get('/hero-test')->getContent())->toContain('pb-40');
});

it('ignores a slide with nothing in it rather than drawing an empty one', function () {
    $image = publishableImage('wide');

    heroPage([
        'heading' => 'First',
        'image' => $image->getKey(),
        'slides' => [
            ['heading' => '', 'image' => $image->getKey()],
            'not an array at all',
        ],
    ]);

    $html = $this->get('/hero-test')->assertOk()->getContent();

    expect(substr_count($html, 'aria-roledescription="slide"'))->toBe(0)
        ->and($html)->not->toContain('data-hero-slider');
});
