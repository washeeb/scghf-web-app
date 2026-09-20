<?php

declare(strict_types=1);

use App\Models\Page;
use App\Support\Settings;
use App\Support\ThemePreference;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| The header, footer and theme switches an editor controls
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    app(Settings::class)->flush();
});

it('offers the third palette in the theme control and honours it on the html element', function () {
    $this->get('/')->assertOk()
        ->assertSee('<option value="vibrant">Vibrant</option>', false);

    $this->withUnencryptedCookie(ThemePreference::COOKIE, 'vibrant')->get('/')
        ->assertOk()
        ->assertSee('class="vibrant"', false)
        ->assertSee('data-theme="vibrant"', false);

    $this->withUnencryptedCookie(ThemePreference::COOKIE, 'dark')->get('/')
        ->assertSee('class="dark"', false);

    expect(app(ThemePreference::class)->isValid('vibrant'))->toBeTrue()
        ->and(app(ThemePreference::class)->isValid('neon'))->toBeFalse();
});

it('names the third palette and can make it the default from the settings', function () {
    app(Settings::class)->set('site.vibrant_theme_label', 'Sunrise');
    app(Settings::class)->set('site.default_theme', 'vibrant');
    app(Settings::class)->flush();

    $this->get('/')->assertOk()
        ->assertSee('<option value="vibrant">Sunrise</option>', false)
        ->assertSee('class="vibrant"', false);
});

it('tells the navigation script whether menus open on hover, from the settings', function () {
    $this->get('/')->assertSee('data-nav-hover="1"', false);

    app(Settings::class)->set('site.nav_open_on_hover', false);
    app(Settings::class)->flush();

    $this->get('/')->assertSee('data-nav-hover="0"', false);
});

it('groups the footer policies as the settings say, and falls back when the setting is broken', function () {
    // Publish two policy pages so the links render.
    foreach (['privacy-policy', 'refund-policy'] as $slug) {
        Page::query()->where('slug', $slug)->first()?->publish();
    }

    $this->get('/')->assertOk()
        ->assertSeeInOrder(['Legal', 'Privacy', 'Giving', 'Refunds']);

    app(Settings::class)->set('site.footer_policy_groups', [
        ['label' => 'Small print', 'slugs' => ['privacy-policy', 'refund-policy']],
    ]);
    app(Settings::class)->flush();

    $this->get('/')->assertSeeInOrder(['Small print', 'Privacy', 'Refunds'])
        ->assertDontSee('>Giving<', false);

    app(Settings::class)->set('site.footer_policy_groups', 'not a list');
    app(Settings::class)->flush();

    $this->get('/')->assertSeeInOrder(['Legal', 'Privacy', 'Giving', 'Refunds']);
});
