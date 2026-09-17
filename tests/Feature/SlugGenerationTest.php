<?php

declare(strict_types=1);

use App\Models\Cause;
use App\Models\Event;
use App\Models\FocusArea;
use App\Models\Page;
use App\Models\Product;
use App\Models\Project;
use App\Models\VolunteerOpportunity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 14 — slugs, on every type that has one
|--------------------------------------------------------------------------
|
| A slug is the public address of a thing. Every content model derives one
| from its title when the editor leaves the field blank, cleans one the
| editor typed, and refuses a duplicate at the database rather than in a
| form — because a form is not the only way a record gets saved. The rule
| is the same everywhere, and this checks it is applied everywhere.
|
*/

/** @return array<string, array{class-string, string, callable}> label => [model, title field, factory] */
function sluggedModels(): array
{
    return [
        'page' => [Page::class, 'title', fn (array $a) => Page::factory()->create($a)],
        'product' => [Product::class, 'name', fn (array $a) => Product::factory()->create($a)],
        'event' => [Event::class, 'title', fn (array $a) => Event::factory()->create($a)],
        'appeal' => [Cause::class, 'title', fn (array $a) => Cause::factory()->create($a)],
        'project' => [Project::class, 'title', fn (array $a) => Project::factory()->create($a)],
        'area of work' => [FocusArea::class, 'name', fn (array $a) => FocusArea::factory()->create($a)],
        'volunteer role' => [VolunteerOpportunity::class, 'title', fn (array $a) => VolunteerOpportunity::factory()->create($a)],
    ];
}

it('derives the slug from the title when none is given, for :dataset', function (string $label) {
    [, $field, $make] = sluggedModels()[$label];

    $record = $make([$field => 'Boreholes for Bongo — Phase 2!', 'slug' => null]);

    expect($record->fresh()->slug)->toBe('boreholes-for-bongo-phase-2');
})->with(array_keys(sluggedModels()));

it('cleans a slug the editor typed rather than trusting it, for :dataset', function (string $label) {
    [, $field, $make] = sluggedModels()[$label];

    $record = $make([$field => 'Anything', 'slug' => '  Our Work/In Bongo (2026) ']);

    expect($record->fresh()->slug)->toBe('our-workin-bongo-2026');
})->with(array_keys(sluggedModels()));

it('keeps the slug the editor chose over the title, for :dataset', function (string $label) {
    // A title can change for the reader without moving the page for search
    // engines and shared links.
    [, $field, $make] = sluggedModels()[$label];

    $record = $make([$field => 'A New Title', 'slug' => 'the-old-address']);

    expect($record->fresh()->slug)->toBe('the-old-address');
})->with(array_keys(sluggedModels()));

it('refuses a duplicate slug at the database, whatever saved it, for :dataset', function (string $label) {
    [, $field, $make] = sluggedModels()[$label];

    $make([$field => 'First', 'slug' => 'same-address']);

    expect(fn () => $make([$field => 'Second', 'slug' => 'same-address']))->toThrow(QueryException::class);
})->with(array_keys(array_diff_key(sluggedModels(), ['page' => true])));

it('lets two pages share a slug under different parents but not the same one', function () {
    $parentA = Page::factory()->create(['slug' => 'about']);
    $parentB = Page::factory()->create(['slug' => 'contact']);

    $childA = Page::factory()->create(['slug' => 'team', 'parent_id' => $parentA->id]);
    $childB = Page::factory()->create(['slug' => 'team', 'parent_id' => $parentB->id]);

    expect($childA->fresh()->path)->toBe('/about/team')
        ->and($childB->fresh()->path)->toBe('/contact/team');

    expect(fn () => Page::factory()->create(['slug' => 'team', 'parent_id' => $parentA->id]))
        ->toThrow(QueryException::class);
});

it('never produces an empty slug from a title with no letters in it', function () {
    // Str::slug('!!!') is ''. An empty slug is a page at the site root or a
    // 404 nobody can explain; a title has to yield an address or fail loudly.
    $page = Page::factory()->make(['title' => '!!! ***', 'slug' => null]);

    expect(fn () => $page->save())->toThrow(InvalidArgumentException::class, 'at least one letter or number');
});
