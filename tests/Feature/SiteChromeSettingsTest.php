<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\User;
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
        ->assertSee('data-theme-option="vibrant"', false)
        ->assertSeeInOrder(['data-theme-option="vibrant"', 'Vibrant'], false);

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
        ->assertSeeInOrder(['data-theme-option="vibrant"', 'Sunrise'], false)
        ->assertSee('class="vibrant"', false)
        // The menu ticks the theme in force.
        ->assertSeeInOrder(['aria-checked="true"', 'data-theme-option="vibrant"'], false);
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

it('draws the footer policy strip only when a policy is live, and the cookie control as a button meanwhile', function () {
    // Fresh install: every policy page is a draft. The strip holds only the
    // cookie control (as a button — no policy page to link to yet), never an
    // empty band between two rules.
    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('<button type="button" data-cookie-consent-manage')
        ->not->toContain('Privacy');

    Page::query()->where('slug', 'privacy-policy')->first()?->publish();
    $this->get('/')->assertSee('aria-label="Policies"', false)->assertSee('Privacy');
});

it('takes every word in the header and footer chrome from the settings', function () {
    $s = app(Settings::class);
    $s->set('header.account_label', 'My space');
    $s->set('header.sign_in_label', 'Log in');
    $s->set('header.theme_system_label', 'Auto');
    $s->set('header.search_label', 'Find');
    $s->set('site.footer_back_to_top_label', 'Up');
    $s->set('site.footer_currency_label', 'Also in');
    $s->set('site.footer_join_label', 'Subscribe me');
    $s->set('site.footer_copyright_prefix', 'Copyright');
    $s->set('header.account_menu', [['route' => 'account.receipts', 'label' => 'My receipts'], ['route' => 'nope.route', 'label' => 'Broken']]);
    $s->flush();

    $this->get('/')->assertOk()
        ->assertSee('Log in')->assertSee('>Auto<', false)->assertSee('aria-label="Find"', false)
        ->assertSee('>Up<', false)->assertSee('Subscribe me')->assertSee('Copyright '.now()->year);

    $this->actingAs(User::factory()->create())->get('/')->assertOk()
        ->assertSee('My space')->assertSee('My receipts')->assertDontSee('Broken')->assertSee('Sign out');
});
