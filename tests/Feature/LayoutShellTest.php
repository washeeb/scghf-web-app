<?php

declare(strict_types=1);

use App\Models\Menu;
use App\Models\ThemeSetting;
use App\Support\ThemePreference;
use App\Support\ThemeTokens;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The layout shell
|--------------------------------------------------------------------------
|
| Two things this file is really about.
|
| THE FLASH. A theme applied by JavaScript after the page has painted is a
| visible flash of the wrong colours on every navigation. The cookie exists so
| the SERVER can put `.dark` on `<html>` in the bytes it sends — nothing to
| correct, nothing to flash. The inline script covers the one case the server
| cannot know: `system`.
|
| THE CMS RULE. Nothing in the shell is a typed string. If a test here starts
| asserting on words rather than on values from the settings layer, something
| has been hardcoded that should not have been.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    ThemeTokens::flush();
});

// ── It renders at all ───────────────────────────────────────────────────────

it('serves the home page', function () {
    $this->get('/')->assertOk();
});

it('renders the shell with no menus seeded', function () {
    /*
     * A fresh environment where the menu seeder has not run must produce a
     * header with no navigation, not a 500 on the home page. A bare header is
     * visibly wrong and somebody fixes it; a site that will not load is an
     * outage.
     */
    expect(Menu::count())->toBe(0);

    $this->get('/')->assertOk();
});

it('renders the shell with the seeded menus', function () {
    $this->seed(PageSeeder::class);
    $this->seed(MenuSeeder::class);

    $this->get('/')->assertOk();
});

// ── No flash of the wrong theme ─────────────────────────────────────────────

it('renders dark on the server when the cookie says dark', function () {
    /*
     * The whole point of the cookie. localStorage is only readable by
     * JavaScript, which runs after the HTML has arrived — so with localStorage
     * alone the server always sends light, the browser paints it, and the
     * script corrects it. That correction IS the flash.
     */
    $this->withUnencryptedCookie(ThemePreference::COOKIE, 'dark')
        ->get('/')
        ->assertOk()
        ->assertSee('class="dark"', escape: false);
});

it('does not render dark when the cookie says light', function () {
    $this->withUnencryptedCookie(ThemePreference::COOKIE, 'light')
        ->get('/')
        ->assertOk()
        ->assertDontSee('class="dark"', escape: false);
});

it('leaves the class off for system and lets the script decide', function () {
    /*
     * The one case the server genuinely cannot answer: the OS setting is not
     * sent with the request. The inline script resolves it before first paint,
     * so there is still nothing to flash.
     */
    $this->withUnencryptedCookie(ThemePreference::COOKIE, 'system')
        ->get('/')
        ->assertOk()
        ->assertDontSee('class="dark"', escape: false)
        ->assertSee('prefers-color-scheme: dark', escape: false);
});

it('puts the theme script inline and before the stylesheet', function () {
    /*
     * Order is the mechanism, not a preference. A `<script src>` — even
     * deferred — runs after the document is parsed, by which point the browser
     * has painted.
     */
    $html = $this->get('/')->getContent();

    $script = strpos($html, 'prefers-color-scheme: dark');
    $stylesheet = strpos($html, 'build/assets/app-');

    expect($script)->not->toBeFalse()
        ->and($stylesheet)->not->toBeFalse()
        ->and($script)->toBeLessThan($stylesheet);
});

it('ignores a cookie holding something that is not a theme', function () {
    // The cookie is attacker-controllable. It reaches an HTML class attribute.
    $this->withCookie(ThemePreference::COOKIE, '"><script>alert(1)</script>')
        ->get('/')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});

// ── The tokens ──────────────────────────────────────────────────────────────

it('emits both themes so a system flip needs no round trip', function () {
    $css = (string) app(ThemeTokens::class)->css();

    expect($css)->toContain(':root{')
        ->and($css)->toContain('.dark{')
        ->and($css)->toContain('--brand-primary:');
});

it('sets color-scheme so the browser chrome matches', function () {
    // Without it a dark page keeps white scrollbars and a blinding autofill
    // dropdown, which reads as broken rather than themed.
    $css = (string) app(ThemeTokens::class)->css();

    expect($css)->toContain('color-scheme:light')
        ->and($css)->toContain('color-scheme:dark');
});

it('takes the palette from the database, not from the stylesheet', function () {
    // The colours are CMS content sampled from the logo pack. Compiling them
    // into app.css would mean a deploy to change the brand colour.
    $brand = ThemeSetting::where('theme', 'light')->where('token', 'brand-primary')->value('value');

    expect((string) app(ThemeTokens::class)->css())->toContain('--brand-primary:'.$brand);
});

it('shows an edit immediately rather than when a cache expires', function () {
    /*
     * A TTL would mean an editor changes the brand colour, sees nothing,
     * refreshes, still sees nothing, and concludes the panel is broken — then
     * it appears ten minutes later while they are looking elsewhere.
     */
    app(ThemeTokens::class)->css();

    ThemeSetting::where('theme', 'light')
        ->where('token', 'brand-primary')
        ->first()
        ->update(['value' => '#123456']);

    expect((string) app(ThemeTokens::class)->css())->toContain('--brand-primary:#123456');
});

it('refuses a token value that would escape its declaration', function () {
    /*
     * These values come from a column an administrator edits and land in an
     * inline <style> block. A value closing the block would be stored XSS with
     * the site's own blessing.
     */
    ThemeSetting::where('theme', 'light')
        ->where('token', 'brand-primary')
        ->first()
        ->update(['value' => 'red}</style><script>alert(1)</script>']);

    $css = (string) app(ThemeTokens::class)->css();

    expect($css)->not->toContain('<script>')
        ->and($css)->not->toContain('</style>');
});

it('refuses a token value that fetches something', function () {
    // A design token has no business making a request, and url() is the usual
    // way CSS exfiltrates data.
    ThemeSetting::where('theme', 'light')
        ->where('token', 'brand-primary')
        ->first()
        ->update(['value' => 'url(https://example.test/a)']);

    expect((string) app(ThemeTokens::class)->css())->not->toContain('example.test');
});

it('still renders a legible page when the tokens cannot be read', function () {
    // A page rendering black on black because a query failed is worse than one
    // rendering in plain greys for a minute.
    Schema::drop('theme_settings');
    ThemeTokens::flush();

    $css = (string) app(ThemeTokens::class)->css();

    /*
     * `--text-primary`, not `--text`.
     *
     * The fallback used to call it `--text` while the seeded palette called it
     * `--text-primary`, and the views followed the fallback — so the site was
     * correct only when the database could NOT be read, and rendered black text
     * on a dark background the rest of the time. `ThemeTokenCoverageTest` keeps
     * the two lists in step now; this assertion is the other end of it.
     */
    expect($css)->toContain('--bg:')
        ->and($css)->toContain('--text-primary:');
});

// ── Accessibility ───────────────────────────────────────────────────────────

it('puts a skip link first, before the navigation', function () {
    /*
     * Without it a keyboard user tabs through every navigation item on every
     * page before reaching the content — and this site's header nav is
     * multi-level.
     */
    $html = $this->get('/')->getContent();

    expect(strpos($html, 'Skip to content'))->toBeLessThan(strpos($html, '<header'));
});

it('marks its landmarks and gives the nav a name', function () {
    $this->seed(PageSeeder::class);
    $this->seed(MenuSeeder::class);

    $this->get('/')
        ->assertSee('id="main-content"', escape: false)
        ->assertSee('aria-label="Primary"', escape: false)
        ->assertSee('<footer', escape: false);
});

it('carries a live region for things a sighted user would see happen', function () {
    $this->get('/')
        ->assertSee('aria-live="polite"', escape: false)
        ->assertSee('id="announcements"', escape: false);
});

it('offers the theme states as a menu, because system is a real choice', function () {
    // Somebody who picked dark should stay dark when their laptop flips at
    // sunset; somebody who picked system should follow it. The control is
    // an icon that opens a menu of menuitemradio buttons, one per state.
    $this->get('/')
        ->assertSee('data-theme-option="system"', escape: false)
        ->assertSee('data-theme-option="light"', escape: false)
        ->assertSee('data-theme-option="dark"', escape: false)
        ->assertSee('role="menuitemradio"', escape: false);
});

// ── Content comes from the CMS ──────────────────────────────────────────────

it('takes the organisation name from settings', function () {
    $this->get('/')->assertSee(setting('general.legal_name'));
});

it('shows the registration details a donor looks for', function () {
    /*
     * A Ghanaian non-profit asking the public for money is expected to show who
     * it is. Its absence is what a scam site has in common with a real one that
     * forgot.
     */
    $this->get('/')
        ->assertSee(setting('general.registration_number'))
        ->assertSee((string) now()->year);
});

it('omits a contact detail that has not been filled in', function () {
    // The settings layer treats an unfilled {{PLACEHOLDER}} as absent, so the
    // footer must not render an empty label or a raw token.
    $this->get('/')->assertDontSee('{{', escape: false);
});

it('noindexes the site until indexing is deliberately switched on', function () {
    // A staging site indexed alongside the real one splits its ranking and
    // confuses donors.
    expect(setting('seo.allow_indexing'))->toBeFalsy();

    $this->get('/')->assertSee('noindex', escape: false);
});

// ── Error pages ─────────────────────────────────────────────────────────────

it('renders a branded 404 inside the site layout', function () {
    /*
     * An unstyled framework error page on a donation site reads as "this is
     * broken", or as a different site entirely — which is the moment a donor
     * abandons a payment.
     */
    $this->get('/no-such-page-exists')
        ->assertNotFound()
        ->assertSee(setting('general.legal_name'))
        ->assertSee('Back to the home page');
});

it('has a branded page for every error a visitor can actually hit', function (string $code) {
    expect(view()->exists("errors.{$code}"))->toBeTrue();
})->with(['403', '404', '419', '429', '500', '503']);
