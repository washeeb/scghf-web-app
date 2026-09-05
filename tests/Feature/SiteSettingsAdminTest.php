<?php

declare(strict_types=1);

use App\Filament\Pages\ManageSettings;
use App\Models\Setting;
use App\Models\SettingHistoryEntry;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The site settings screen, and the things it turns on
|--------------------------------------------------------------------------
|
| THE TABLE HAD NO SCREEN. The settings layer has held the foundation's legal
| name, registration number, phone numbers, bank details and receipt wording
| since Phase 3, read by the layout, the footer and every email template, with
| nothing in front of it. Editing any of them meant database access — the exact
| opposite of CLAUDE.md's CMS rule.
|
| THREE COLUMNS THAT WERE DECORATION. `validation` was written by the seeder and
| read by nothing; `options` was never written at all, which left the one Select
| setting unfillable; `updated_by` existed on both settings tables, resolved a
| relationship, and was always null.
|
| SETTINGS NOBODY COULD SEE. The header and footer settings were seeded in this
| module. A setting no view reads is the gap CLAUDE.md's standing rule is about,
| so each one is asserted here against the page it changes.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function settingsEditor(): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['settings.manage', 'appearance.manage']);

    return $user;
}

// ── Who may open it ─────────────────────────────────────────────────────────

it('opens for somebody who may manage settings', function () {
    $this->actingAs(settingsEditor());

    Livewire::test(ManageSettings::class)->assertOk();
});

it('is closed to somebody who may not', function () {
    // Deny by default. A staff account with no settings permission is the
    // commonest reader of this screen and must not be one of its editors —
    // `donations.receipt_prefix` and the bank account number live here.
    $this->actingAs(User::factory()->staff()->withTwoFactor()->create());

    expect(ManageSettings::canAccess())->toBeFalse();
});

// ── Saving ──────────────────────────────────────────────────────────────────

it('saves a value and shows it on the site immediately', function () {
    /*
     * The whole table is cached as one array, for ever, and busted on save. A
     * settings change that does not appear until a cache expires is
     * indistinguishable, to the person who made it, from one that did not save
     * — so they make it again, and again.
     */
    $this->actingAs(settingsEditor());

    $this->get('/'); // warm the cache

    Livewire::test(ManageSettings::class)
        ->fillForm(['contact' => ['phone_primary' => '0241234567']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(setting('contact.phone_primary'))->toBe('0241234567');

    $this->get('/')->assertSee('0241234567');
});

it('refuses a phone number that is not a Ghanaian one', function () {
    /*
     * `Setting::validation` was seeded from `SettingType::validationRule()`
     * since Phase 3 and read by nothing, so every rule in that column was
     * decoration. A malformed number in `contact.phone_primary` is a `tel:`
     * link that dials nowhere, on every page of the site.
     */
    $this->actingAs(settingsEditor());

    Livewire::test(ManageSettings::class)
        ->fillForm(['contact' => ['phone_primary' => 'call the office']])
        ->call('save')
        ->assertHasFormErrors(['contact.phone_primary']);
});

it('lets a tab save while other fields on it are still placeholders', function () {
    /*
     * `{{PHONE_SECONDARY}}` is not a valid Ghanaian number, and a strict
     * reading would refuse the whole Contact tab until somebody filled in every
     * field on it — including the ones they came here to avoid. Unfilled is
     * reported by the preflight command, not by blocking a save.
     */
    $this->actingAs(settingsEditor());

    expect(Setting::where('group', 'contact')->where('key', 'phone_secondary')->value('value'))
        ->toBe('{{PHONE_SECONDARY}}');

    Livewire::test(ManageSettings::class)
        ->fillForm(['contact' => ['city' => 'Bolgatanga']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(setting('contact.city'))->toBe('Bolgatanga');
});

it('records what a setting used to be', function () {
    /*
     * The one question a history exists to answer: "the receipts we issued in
     * March say something else, and can you prove what it said then?"
     */
    $this->actingAs(settingsEditor());

    Livewire::test(ManageSettings::class)
        ->fillForm(['general' => ['legal_name' => 'A Different Name']])
        ->call('save');

    $entry = SettingHistoryEntry::where('setting_key', 'general.legal_name')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_value)->toBe('A Different Name')
        ->and($entry->old_value)->toBe("St. Cecilia's Greater Hope Foundations");
});

it('records who changed it', function () {
    // `updated_by` has been on the settings table since the migration and was
    // written by nothing, so the column existed, the relationship resolved,
    // and the answer was always "nobody" — an audit field that reads as a fact.
    $editor = settingsEditor();
    $this->actingAs($editor);

    Livewire::test(ManageSettings::class)
        ->fillForm(['general' => ['motto' => 'Hope, first.']])
        ->call('save');

    expect(Setting::where('group', 'general')->where('key', 'motto')->value('updated_by'))
        ->toBe($editor->getKey());
});

it('keeps an amount in pesewas', function () {
    // Every amount in this application is an integer of minor units, and this
    // is the one screen where a person types one directly.
    $this->actingAs(settingsEditor());

    Livewire::test(ManageSettings::class)
        ->fillForm(['donations' => ['min_amount' => 2000]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::where('group', 'donations')->where('key', 'min_amount')->value('value'))
        ->toBe('2000')
        ->and(setting('donations.min_amount')->minor)->toBe(2000);
});

it('stores a JSON setting as JSON rather than as a quoted string', function () {
    // Handed the textarea's contents unchanged, `setTypedValue()` would encode
    // the string — storing "[\"Faith\"]" and casting it back to a string.
    $this->actingAs(settingsEditor());

    Livewire::test(ManageSettings::class)
        ->fillForm(['general' => ['core_values' => '["Faith","Compassion"]']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(setting('general.core_values'))->toBe(['Faith', 'Compassion']);
});

it('gives the one Select setting something to select', function () {
    /*
     * `site.default_theme` was seeded as a Select and the `options` column was
     * never written, so the field had an empty list — a setting on the screen
     * that nobody could change.
     */
    expect(Setting::where('group', 'site')->where('key', 'default_theme')->value('options'))
        ->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The announcement bar moved
|--------------------------------------------------------------------------
|
| Seven tests stood here, over an `announcement.*` settings group this module
| added. They are gone, and so is the group.
|
| The `announcements` table had been in the schema since Phase 3 doing the same
| job — with dismissal, path targeting, three placements, impression counting
| and a scheduling window — and nothing rendered it, so the settings version
| looked like the only one there was. Two mechanisms for one bar is worse than
| either alone: the foundation edits one and the site shows the other.
|
| The table won. The bar, its dates, its targeting and its counters are tested
| in `ContentModulesTest`.
|
*/

// ── The header and footer settings ──────────────────────────────────────────

it('keeps the header in view when the foundation asks it to', function () {
    // The Donate button is the point: a sticky header keeps it reachable the
    // whole way down a long appeal page.
    $this->get('/')->assertSee('sticky top-0', escape: false);

    app(Settings::class)->set('header.is_sticky', false);

    $this->get('/')->assertDontSee('sticky top-0', escape: false);
});

it('shows the top bar only when it is turned on', function () {
    app(Settings::class)->set('contact.office_hours', 'Mon–Fri, 8am–5pm');

    $this->get('/')->assertDontSee('Mon–Fri, 8am–5pm', escape: false);

    app(Settings::class)->set('header.show_top_bar', true);

    $this->get('/')->assertSee('Mon–Fri, 8am–5pm', escape: false);
});

it('takes the footer headings from the settings layer', function () {
    // Read by the footer since Phase 4 with no row behind them, so they could
    // not be changed without a deploy.
    app(Settings::class)->set('site.footer_newsletter_heading', 'Hear from us');

    $this->get('/')->assertSee('Hear from us');
});

it('can be asked not to show a back-to-top link', function () {
    $this->get('/')->assertSee('Back to top');

    app(Settings::class)->set('site.show_back_to_top', false);

    $this->get('/')->assertDontSee('Back to top');
});
