<?php

declare(strict_types=1);

use App\Models\Division;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\PageSection;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LaunchContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| The four division pages
|--------------------------------------------------------------------------
|
| A page each for health, education, orphans/widows/widowers and missions.
| They exist because the hero's slides, the header menu and the division
| cards all wanted somewhere to point, and `/what-we-do` — a list of
| twenty-nine focus areas — is not an answer to "what do you do about
| health?".
|
| The thing this file is really holding still is the slug: they are NOT
| under `/what-we-do`, because that path belongs to the focus-area route and
| would swallow them.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    // DatabaseSeeder makes the pages; LaunchContentSeeder is what writes the
    // words into them and publishes them.
    $this->seed(LaunchContentSeeder::class);
});

/** page slug => the division record it is built from. */
$slugs = [
    'health' => 'life-spring',
    'education' => 'brightpath',
    'orphans-widows-and-widowers' => 'legacy-of-love',
    'missions' => 'every-soul-missions',
];

// A dataset of pairs: an associative array would arrive as one argument.
$pairs = array_map(fn (string $slug, string $division): array => [$slug, $division], array_keys($slugs), array_values($slugs));

it('publishes a page for each division, built from the division\'s own record', function (string $slug, string $division) {
    $page = Page::query()->where('slug', $slug)->first();

    expect($page)->not->toBeNull()
        ->and($page->isLive())->toBeTrue();

    $html = $this->get('/'.$slug)->assertOk()->getContent();
    $record = Division::query()->where('slug', $division)->firstOrFail();

    // The summary and the focus areas come from the record an editor keeps
    // in the panel, so the page cannot drift from the division it is about.
    expect($html)->toContain(e($record->name))
        ->and($html)->toContain(e($record->summary));

    foreach ($record->focusAreas()->orderBy('sort_order')->limit(3)->get() as $area) {
        expect($html)->toContain(e($area->name));
    }
})->with($pairs);

it('keeps them out of the focus-area route, which would swallow them', function (string $slug) {
    // `/what-we-do/{focusArea:slug}` matches before any page would, so a
    // division page under that path is a 404 waiting to happen.
    $this->get('/what-we-do/'.$slug)->assertNotFound();
    $this->get('/'.$slug)->assertOk();
})->with(array_keys($slugs));

it('lists them under Our Divisions in the header', function () {
    $menu = Menu::query()->where('key', 'header')->firstOrFail();

    $parent = $menu->items()->whereNull('parent_id')->where('route_name', 'focus-areas.index')->firstOrFail();

    $children = $menu->items()->where('parent_id', $parent->id)->with('page')->orderBy('sort_order')->get();

    expect($children->pluck('page.slug')->all())
        ->toBe(['health', 'education', 'orphans-widows-and-widowers', 'missions']);

    // Page links, not addresses: renaming a slug moves the menu with it.
    expect($children->every(fn (MenuItem $item): bool => $item->page_id !== null))->toBeTrue();
});

it('sends the hero slides to those pages rather than to an address that cannot exist', function () {
    $hero = PageSection::query()
        ->whereIn('page_id', Page::query()->where('slug', 'home')->pluck('id'))
        ->where('block_type', 'hero')
        ->firstOrFail();

    $urls = collect((array) ($hero->data['slides'] ?? []))
        ->flatMap(fn (array $slide): array => [$slide['primary_cta_url'] ?? null, $slide['secondary_cta_url'] ?? null])
        ->filter()
        ->unique();

    expect($urls)->not->toBeEmpty();

    foreach ($urls as $url) {
        expect($url)->not->toStartWith('/what-we-do/');
        $this->get($url)->assertOk();
    }
});
