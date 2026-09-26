<?php

declare(strict_types=1);

use App\Console\Commands\LaunchImages;
use App\Enums\PageStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\Media;
use App\Models\Page;
use App\Models\Project;
use App\Models\Testimonial;
use App\Support\HealthCheck;
use App\Support\LaunchChecks;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\LaunchContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Launch content: the photographs, the licence, the seeder
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    $this->seed(DatabaseSeeder::class);
    app(Settings::class)->flush();
});

/** A real JPEG, big enough for the upload policy, small enough for a test. */
function fixtureJpeg(): string
{
    $img = imagecreatetruecolor(640, 427);
    imagefilledrectangle($img, 0, 0, 639, 426, imagecolorallocate($img, 120, 90, 60));
    imagefilledellipse($img, 320, 213, 300, 200, imagecolorallocate($img, 230, 200, 150));
    ob_start();
    imagejpeg($img, null, 80);

    return (string) ob_get_clean();
}

function fakeUnsplash(): void
{
    Http::fake(['images.unsplash.com/*' => Http::response(fixtureJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
}

// ── The licence ──────────────────────────────────────────────────────────────

it('lets a licensed stock photograph of a person be published without a consent, and still gates our own', function () {
    $stock = Media::factory()->create(['depicts_people' => true, 'licence' => Media::LICENCE_STOCK, 'licence_url' => 'https://unsplash.com/photos/x', 'metadata_stripped_at' => now(), 'alt_text' => 'A person']);
    $own = Media::factory()->create(['depicts_people' => true, 'licence' => Media::LICENCE_OWN, 'metadata_stripped_at' => now(), 'alt_text' => 'A person']);

    expect($stock->isStock())->toBeTrue()
        ->and($stock->publicationRejectionReason())->toBeNull()
        ->and($own->publicationRejectionReason())->toContain('consent');
});

// ── The images command ───────────────────────────────────────────────────────

it('fetches every picture in the manifest through the media library, marked as stock, and fetches nothing twice', function () {
    fakeUnsplash();

    $this->artisan('scghf:launch-images')->assertSuccessful();

    $manifest = LaunchImages::manifest();
    $rows = Media::query()->where('custom_properties->'.LaunchImages::PROPERTY, '!=', '')->get();

    expect($rows)->toHaveCount(count($manifest))
        ->and($rows->every(fn (Media $m): bool => $m->licence === Media::LICENCE_STOCK))->toBeTrue()
        ->and($rows->every(fn (Media $m): bool => $m->depicts_people && $m->publicationRejectionReason() === null))->toBeTrue()
        ->and($rows->every(fn (Media $m): bool => str_contains((string) $m->licence_url, 'unsplash.com')))->toBeTrue()
        ->and($rows->every(fn (Media $m): bool => filled($m->alt_text) && str_ends_with((string) $m->credit, ', Unsplash')))->toBeTrue()
        ->and(LaunchImages::forSlot('home-hero'))->not->toBeNull();

    Http::fake(['images.unsplash.com/*' => Http::response('', 500)]);

    $this->artisan('scghf:launch-images')
        ->expectsOutputToContain('0 missing')
        ->assertSuccessful();

    expect(Media::query()->count())->toBe($rows->count());
});

it('reports what is missing with --check and fetches nothing', function () {
    Http::fake();

    $this->artisan('scghf:launch-images', ['--check' => true])
        ->expectsOutputToContain('missing: home-hero')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(Media::query()->count())->toBe(0);
});

// ── The seeder ───────────────────────────────────────────────────────────────

it('seeds the launch site with its pictures, publishes the pages, and runs twice without changing anything', function () {
    fakeUnsplash();
    $this->artisan('scghf:launch-images')->assertSuccessful();

    $this->seed(LaunchContentSeeder::class);

    $home = Page::query()->where('slug', 'home')->first();
    expect($home->status)->toBe(PageStatus::Published)
        ->and($home->sections()->where('block_type', 'hero')->exists())->toBeTrue()
        ->and($home->sections()->where('block_type', 'hero')->first()->data['image'])->toBe(LaunchImages::forSlot('home-hero')->getKey());

    expect(Project::query()->live()->count())->toBeGreaterThanOrEqual(8)
        ->and(Project::query()->whereNull('featured_image_id')->count())->toBe(0)
        ->and(Cause::query()->whereIn('slug', ['keep-a-student-in-school', 'a-clinic-day-for-a-village', 'a-starter-kit-for-a-widow', 'bibles-for-an-outreach'])->where('is_published', true)->count())->toBe(4)
        // Placeholder voices are never published: there is nobody to consent.
        ->and(Testimonial::query()->where('is_published', true)->count())->toBe(0)
        ->and(Testimonial::query()->count())->toBe(2);

    // The legal pages are the trustees' undertakings and stay drafts.
    expect(Page::query()->where('slug', 'privacy-policy')->first()->status)->toBe(PageStatus::Draft);

    $counts = fn (): array => [Project::count(), Cause::count(), Page::query()->whereHas('sections')->count(), Testimonial::count()];
    $before = $counts();
    $this->seed(LaunchContentSeeder::class);
    expect($counts())->toBe($before);

    // Every public page the seeder wrote renders.
    foreach (['/', '/about', '/about/our-story', '/about/transparency', '/get-involved', '/contact', '/faq', '/projects', '/appeals', '/news', '/events', '/what-we-do'] as $path) {
        $this->get($path)->assertOk();
    }
});

it('seeds the words without the pictures when no picture has been fetched', function () {
    $this->seed(LaunchContentSeeder::class);

    expect(Project::query()->count())->toBeGreaterThanOrEqual(8)
        ->and(Project::query()->whereNotNull('featured_image_id')->count())->toBe(0);

    $this->get('/')->assertOk()->assertSee('Turning remembrance into impact');
});

it('is counted by the launch check until every placeholder is replaced', function () {
    $this->seed(LaunchContentSeeder::class);

    $row = app(LaunchChecks::class)->checks()->firstWhere('key', 'placeholders');
    expect($row->status)->toBe(HealthCheck::CRITICAL)
        ->and($row->value)->toContain('8 projects')
        ->and($row->value)->toContain('4 appeals')
        ->and($row->value)->toContain('2 testimonials');

    // Replace them (here: strip the marker) and the row clears.
    foreach (['projects' => 'description', 'causes' => 'description', 'posts' => 'body', 'events' => 'description', 'testimonials' => 'author_role', 'impact_metrics' => 'description', 'galleries' => 'description'] as $table => $column) {
        DB::table($table)->update([$column => DB::raw("REPLACE({$column}, '".LaunchContentSeeder::PLACEHOLDER."', '')")]);
    }

    expect(app(LaunchChecks::class)->checks()->firstWhere('key', 'placeholders')->status)->toBe(HealthCheck::OK);
});

it('makes the demo seeder keep its accounts and transactions but not its invented programmes once the launch content exists', function () {
    Mail::fake();
    $this->seed(LaunchContentSeeder::class);
    $this->seed(DemoDataSeeder::class);

    expect(Project::query()->where('slug', 'bongo-school-kits')->exists())->toBeFalse()
        ->and(Donation::query()->count())->toBe(36)
        ->and(Donation::query()->whereIn('cause_id', Cause::query()->whereIn('slug', ['keep-a-student-in-school', 'a-clinic-day-for-a-village', 'a-starter-kit-for-a-widow', 'bibles-for-an-outreach'])->pluck('id'))->exists())->toBeTrue();
});
