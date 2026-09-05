<?php

declare(strict_types=1);

use App\Enums\MenuItemLinkType;
use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Filament\Resources\ThemeSettings\Pages\ListThemeSettings;
use App\Filament\Resources\ThemeSettings\ThemeSettingResource;
use App\Models\Menu;
use App\Models\Page;
use App\Models\ThemeSetting;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\ThemeTokens;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The menu builder and the theme editor
|--------------------------------------------------------------------------
|
| A LINK IS A RELATIONSHIP, NOT A URL. Choosing a page stores `page_id`, so the
| item follows that page when its slug changes. A hardcoded URL in a menu is
| how a site accumulates broken navigation nobody notices.
|
| THE CONTRAST CHECKER FINALLY HAS A FACE. `ThemeSetting::contrastRatio()` and
| `meetsContrast()` have existed since Phase 3 with nothing showing them, which
| meant a palette could fail WCAG AA and the panel would never say so.
|
| RE-SEEDING MUST NOT UNDO THE FOUNDATION'S WORK. `SettingsSeeder` is explicit
| about that; `ThemeSettingsSeeder` did the opposite, two lines away in the same
| list.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
});

/** Somebody who may run the website. */
function siteEditor(): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['menus.manage', 'settings.manage', 'appearance.manage', 'theme.colours.manage', 'pages.view']);

    return $user;
}

// ── The menu builder ────────────────────────────────────────────────────────

it('opens the menu list', function () {
    $this->seed(PageSeeder::class);
    $this->seed(MenuSeeder::class);

    $this->actingAs(siteEditor());

    Livewire::test(ListMenus::class)->assertOk();
});

it('has no create action, because the layout decides which menus exist', function () {
    /*
     * The templates ask for a menu by key. One nobody draws is a menu nothing
     * shows, and one that is missing is a part of the page that renders empty.
     */
    expect(MenuResource::getPages())->not->toHaveKey('create');
});

it('stores a page link as a relationship rather than a URL', function () {
    /*
     * The whole reason `MenuItemLinkType` exists. An item pointing at a page by
     * id follows that page when its address changes; one holding a typed URL
     * silently becomes a link into a 404 of the site's own making.
     */
    $this->seed(PageSeeder::class);

    $menu = Menu::create(['key' => 'test', 'name' => 'Test menu', 'max_depth' => 1]);

    // Not the homepage: its path is `/` whatever its slug says, so it is the
    // one page that cannot demonstrate anything about slugs.
    $page = Page::where('is_homepage', false)->firstOrFail();

    $item = $menu->items()->create([
        'label' => 'About',
        'link_type' => MenuItemLinkType::Page,
        'page_id' => $page->getKey(),
    ]);

    $page->update(['slug' => 'a-completely-different-address']);

    expect($item->fresh()->resolveUrl())->toContain('a-completely-different-address');
});

it('refuses to nest deeper than the layout can draw', function () {
    /*
     * Enforced on the model rather than in the form, so it holds for a seeder
     * and an import too. A menu nested deeper than the layout renders would
     * silently lose its deepest items — the admin sees them saved and the
     * visitor never sees them at all.
     */
    $menu = Menu::create(['key' => 'flat', 'name' => 'Flat menu', 'max_depth' => 0]);

    $parent = $menu->items()->create(['label' => 'Parent', 'link_type' => MenuItemLinkType::Heading]);

    expect(fn () => $menu->items()->create([
        'label' => 'Child',
        'link_type' => MenuItemLinkType::Heading,
        'parent_id' => $parent->getKey(),
    ]))->toThrow(RuntimeException::class);
});

it('opens an external link in a new tab without being asked', function () {
    // An external link opening in the same tab loses the visitor, and on a
    // donation page that is a real cost.
    $menu = Menu::create(['key' => 'test', 'name' => 'Test', 'max_depth' => 1]);

    $item = $menu->items()->create([
        'label' => 'Our Facebook page',
        'link_type' => MenuItemLinkType::External,
        'url' => 'https://example.test',
    ]);

    expect($item->fresh()->opens_in_new_tab)->toBeTrue();
});

it('edits a menu through the panel', function () {
    $this->seed(PageSeeder::class);
    $this->seed(MenuSeeder::class);

    $this->actingAs(siteEditor());

    $menu = Menu::where('key', 'footer_legal')->firstOrFail();

    // `Menu::getRouteKeyName()` is `key`, so the panel addresses a menu by the
    // same name the layout looks it up by — /menus/footer_legal/edit.
    Livewire::test(EditMenu::class, ['record' => $menu->getRouteKey()])
        ->assertOk()
        ->fillForm(['description' => 'The legal strip at the foot of every page.'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($menu->fresh()->description)->toBe('The legal strip at the foot of every page.');
});

// ── The theme editor ────────────────────────────────────────────────────────

it('opens the theme list', function () {
    $this->actingAs(siteEditor());

    Livewire::test(ListThemeSettings::class)->assertOk();
});

it('reports a palette that fails WCAG AA', function () {
    /*
     * The check has existed since Phase 3 and had nothing in front of it, so a
     * palette could fail and the panel would never say. Every failure here is
     * text somebody cannot read.
     */
    expect(ThemeSettingResource::failingCount())->toBe(0);

    ThemeSetting::query()
        ->withContrastObligation()
        ->first()
        ->update(['value' => '#F5F5F5']);

    expect(ThemeSettingResource::failingCount())->toBeGreaterThan(0);
});

it('shows no badge while the palette is legible', function () {
    // A permanent badge is one people stop reading.
    expect(ThemeSettingResource::getNavigationBadge())->toBeNull();
});

it('suggests a legible colour for a token that is failing', function () {
    $this->actingAs(siteEditor());

    $token = ThemeSetting::query()->withContrastObligation()->first();
    $token->update(['value' => '#F5F5F5']);

    expect($token->fresh()->meetsContrast())->toBeFalse();

    Livewire::test(ListThemeSettings::class)
        ->callTableAction('suggest', $token)
        ->assertHasNoTableActionErrors();

    expect($token->fresh()->meetsContrast())->toBeTrue();
});

it('puts the palette back to the brand defaults', function () {
    /*
     * The palette is the one part of the CMS where an afternoon of small
     * adjustments leaves something nobody can unpick — because nobody remembers
     * what nine hex codes used to be.
     */
    $this->actingAs(siteEditor());

    $token = ThemeSetting::where('theme', 'light')->where('token', 'brand-primary')->firstOrFail();
    $original = $token->value;

    $token->update(['value' => '#FF00FF']);

    Livewire::test(ListThemeSettings::class)->callAction('reset');

    expect(ThemeSetting::where('theme', 'light')->where('token', 'brand-primary')->value('value'))
        ->toBe($original);
});

it('shows an edited palette immediately rather than when a cache expires', function () {
    // The inlined <style> block is cached forever and busted by the model's
    // save hook — a reset that bypasses the hook has to flush it itself.
    $this->actingAs(siteEditor());

    app(ThemeTokens::class)->css();

    ThemeSetting::where('theme', 'light')
        ->where('token', 'brand-primary')
        ->first()
        ->update(['value' => '#123456']);

    expect((string) app(ThemeTokens::class)->css())->toContain('#123456');

    Livewire::test(ListThemeSettings::class)->callAction('reset');

    expect((string) app(ThemeTokens::class)->css())->not->toContain('#123456');
});

// ── Re-seeding is safe ──────────────────────────────────────────────────────

it('does not undo the foundation\'s palette when the seeder runs again', function () {
    /*
     * ⚠ The one that was wrong. `ThemeSettingsSeeder` used `updateOrCreate`
     * and overwrote the value, while `SettingsSeeder` two lines above it in
     * `DatabaseSeeder` is explicit that re-running must never undo somebody's
     * work. The runbook's advice for picking up newly added settings is to
     * re-run the seeders — following it would have thrown away every colour the
     * foundation had chosen and left their address intact.
     */
    ThemeSetting::where('theme', 'light')
        ->where('token', 'brand-primary')
        ->first()
        ->update(['value' => '#123456']);

    $this->seed(ThemeSettingsSeeder::class);

    expect(ThemeSetting::where('theme', 'light')->where('token', 'brand-primary')->value('value'))
        ->toBe('#123456');
});

it('still refreshes the metadata when the seeder runs again', function () {
    // A corrected label, or a contrast obligation the design added later,
    // should reach an existing install.
    $token = ThemeSetting::where('theme', 'light')->where('token', 'brand-primary')->firstOrFail();
    $token->update(['label' => 'Something somebody typed']);

    $this->seed(ThemeSettingsSeeder::class);

    expect($token->fresh()->label)->not->toBe('Something somebody typed');
});

// ── Settings the views were reading with nothing behind them ────────────────

it('seeds the footer headings the footer already reads', function () {
    /*
     * Read since Phase 4 with no row behind them, so the headings could not be
     * changed without a deploy — the CMS rule says otherwise.
     */
    expect(setting('site.footer_primary_heading'))->not->toBeNull()
        ->and(setting('site.footer_support_heading'))->not->toBeNull();
});

it('gives the emails an organisation name to sign off with', function () {
    /*
     * The mail layouts read `organisation.legal_name` falling back to
     * `general.site_name`, and neither existed — so every email this
     * application sent had a blank line where the foundation's name belongs.
     */
    expect(setting('general.legal_name'))->not->toBeNull();
});
