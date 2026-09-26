<?php

declare(strict_types=1);

use App\Models\Media;
use App\Support\Features;
use App\Support\LaunchChecks;
use App\Support\Pwa;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Wave 1 — the progressive web app
|--------------------------------------------------------------------------
|
| A manifest built from the CMS, icons rendered from the uploaded logo, a
| service worker that never caches anything personal or transactional, and
| an offline page that still shows the Mobile Money number. All of it
| behind FEATURE_PWA_OFFLINE, and all of it gone when the flag is off.
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    app(Settings::class)->flush();
    Storage::fake('local');

    config(['features.pwa_offline' => true]);
    app(Features::class)->flush();
});

function flagOff(): void
{
    config(['features.pwa_offline' => false]);
    app(Features::class)->flush();
}

// ── The manifest ────────────────────────────────────────────────────────────

it('serves a manifest built from the settings, not from code', function () {
    app(Settings::class)->set('general.short_name', 'Greater Hope');
    app(Settings::class)->set('general.wordmark', 'SCGHF');

    $response = $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json');

    $manifest = $response->json();

    expect($manifest['name'])->toBe('Greater Hope')
        ->and($manifest['short_name'])->toBe('SCGHF')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toStartWith('/')
        ->and($manifest['theme_color'])->toMatch('/^#[0-9a-f]{6}$/i')
        ->and($manifest['background_color'])->toMatch('/^#[0-9a-f]{6}$/i')
        ->and(array_column($manifest['icons'], 'sizes'))->toBe(['192x192', '512x512'])
        ->and($manifest['icons'][0]['purpose'])->toBe('any maskable')
        ->and($manifest['shortcuts'][0]['url'])->toStartWith('/donate');
});

it('links the manifest from every page and marks the body for the script', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<link rel="manifest" href="'.url('/manifest.webmanifest').'">', false)
        ->assertSee('data-pwa="on"', false)
        ->assertSee('data-pwa-install', false);
});

// ── The icons ───────────────────────────────────────────────────────────────

it('renders a brand-coloured PNG tile when no logo has been uploaded', function () {
    $response = $this->get('/pwa/icon-192.png')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $image = imagecreatefromstring($response->getContent());

    expect($image)->not->toBeFalse()
        ->and(imagesx($image))->toBe(192)
        ->and(imagesy($image))->toBe(192);

    // The corner and the centre are the same colour: a plain tile.
    expect(imagecolorat($image, 2, 2))->toBe(imagecolorat($image, 96, 96));
});

it('places the uploaded logo in the centre of the tile and caches the result', function () {
    Storage::fake('public');

    $media = Media::factory()->sanitised()->create([
        'file_name' => 'logo.png',
        'mime_type' => 'image/png',
        'disk' => 'public',
    ]);

    // A solid red square as the logo.
    $logo = imagecreatetruecolor(100, 100);
    imagefill($logo, 0, 0, imagecolorallocate($logo, 255, 0, 0));
    ob_start();
    imagepng($logo);
    Storage::disk('public')->put($media->getPathRelativeToRoot(), (string) ob_get_clean());

    app(Settings::class)->set('header.logo_light', $media->id);

    $image = imagecreatefromstring($this->get('/pwa/icon-512.png')->assertOk()->getContent());
    $centre = imagecolorsforindex($image, imagecolorat($image, 256, 256));
    $corner = imagecolorsforindex($image, imagecolorat($image, 4, 4));

    expect($centre['red'])->toBe(255)
        ->and($centre['green'])->toBe(0)
        ->and($corner)->not->toBe($centre);

    $version = app(Pwa::class)->iconVersion();
    Storage::disk('local')->assertExists("pwa/icon-512-{$version}.png");

    // A new logo is a new URL — the old icon is never served stale.
    $this->travel(1)->minutes();
    $media->touch();
    app(Settings::class)->flush();

    expect(app(Pwa::class)->iconVersion())->not->toBe($version);
});

it('404s an icon size it does not make', function () {
    $this->get('/pwa/icon-100.png')->assertNotFound();
});

// ── The worker ──────────────────────────────────────────────────────────────

it('serves a worker that precaches the shell and never touches money or accounts', function () {
    $response = $this->get('/sw.js')
        ->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertHeader('Cache-Control', 'no-cache, private');

    $js = $response->getContent();

    expect($js)->toContain('const ENABLED = true;')
        ->toContain('"/offline"')
        ->toContain('"/fonts/Inter-latin.woff2"')
        ->not->toContain('__PRECACHE__')
        ->not->toContain('__NEVER_CACHE__')
        ->not->toContain('__CACHE_NAME__');

    preg_match('/const NEVER = (\[.*?\]);/s', $js, $m);
    $never = json_decode($m[1], true);

    foreach (['donate', 'donate/*', 'checkout/*', 'basket', 'account/*', 'livewire/*', 'sw.js', trim((string) config('admin.path'), '/').'/*'] as $path) {
        expect($never)->toContain($path);
    }
});

it('tells an installed worker to unregister itself when the flag is off', function () {
    flagOff();

    $this->get('/sw.js')->assertOk()->assertSee('const ENABLED = false;', false);
});

// ── The offline page ────────────────────────────────────────────────────────

it('shows the Mobile Money number on the offline page so a lost connection is a delayed gift', function () {
    app(Settings::class)->set('banking.momo_number', '024 123 4567');
    app(Settings::class)->set('banking.momo_name', 'SCGHF');

    $this->get('/offline')
        ->assertOk()
        ->assertSee('You are offline')
        ->assertSee('024 123 4567')
        ->assertSee('SCGHF')
        ->assertSee('noindex', false)
        ->assertSee('data-offline-page', false);
});

it('leaves the banking blocks out when nothing is set', function () {
    foreach (['banking.momo_number', 'banking.account_number'] as $key) {
        app(Settings::class)->set($key, '');
    }

    $this->get('/offline')->assertOk()->assertDontSee('Mobile Money')->assertDontSee('Account number');
});

// ── The flag ────────────────────────────────────────────────────────────────

it('is a 404 and an unlinked manifest with the flag off', function () {
    flagOff();

    $this->get('/manifest.webmanifest')->assertNotFound();
    $this->get('/pwa/icon-192.png')->assertNotFound();
    $this->get('/')
        ->assertOk()
        ->assertDontSee('rel="manifest"', false)
        ->assertSee('data-pwa="off"', false)
        ->assertDontSee('data-pwa-install', false);
});

it('is no longer an unbuilt flag for the launch check', function () {
    $reflection = new ReflectionClassConstant(LaunchChecks::class, 'UNBUILT_FLAGS');

    expect($reflection->getValue())->not->toHaveKey('pwa_offline');
});
