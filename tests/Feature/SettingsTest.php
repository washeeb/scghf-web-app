<?php

declare(strict_types=1);

use App\Enums\SettingType;
use App\Models\Setting;
use App\Support\Settings;
use App\ValueObjects\Money;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = app(Settings::class);
});

it('reads and writes a value through the helper', function () {
    setting()->set('contact.phone_primary', '+233241234567', SettingType::Phone);

    expect(setting('contact.phone_primary'))->toBe('+233241234567');
});

it('returns the default for an unknown key rather than throwing', function () {
    // A missing setting must never take a page down.
    expect(setting('nope.missing', 'fallback'))->toBe('fallback')
        ->and(setting('nope.missing'))->toBeNull();
});

it('rejects a key without a group', function () {
    $this->settings->set('nogroup', 'x');
})->throws(InvalidArgumentException::class);

// ── The placeholder contract ─────────────────────────────────────────────────

it('treats an unfilled placeholder as absent', function () {
    $this->seed(SettingsSeeder::class);

    // Seeded as {{PHONE_PRIMARY}}. A donor must never see a raw token.
    expect(setting('contact.phone_primary'))->toBeNull()
        ->and(setting('contact.phone_primary', 'not set'))->toBe('not set')
        ->and($this->settings->has('contact.phone_primary'))->toBeFalse();
});

it('reports every setting still awaiting a real value', function () {
    $this->seed(SettingsSeeder::class);

    $unfilled = $this->settings->unfilled();

    expect($unfilled)->not->toBeEmpty()
        ->and($unfilled->pluck('key'))->toContain('phone_primary', 'registration_number', 'tin');

    // Values the profile document did supply are not flagged.
    expect($unfilled->pluck('key'))->not->toContain('legal_name', 'motto');
});

// ── Typing ───────────────────────────────────────────────────────────────────

it('casts each type on the way out', function () {
    $this->settings->set('t.count', 42, SettingType::Integer);
    $this->settings->set('t.flag', true, SettingType::Boolean);
    $this->settings->set('t.list', [1, 2, 3], SettingType::Json);
    $this->settings->set('t.amount', Money::ofMajor('50.00'), SettingType::Money);

    expect(setting('t.count'))->toBe(42)
        ->and(setting('t.flag'))->toBeTrue()
        ->and(setting('t.list'))->toBe([1, 2, 3])
        ->and(setting('t.amount'))->toBeInstanceOf(Money::class)
        ->and(setting('t.amount')->minor)->toBe(5000);
});

it('round-trips money through minor units with no float anywhere', function () {
    $this->settings->set('t.amount', Money::ofMajor('1234.56'), SettingType::Money);

    expect(Setting::where('key', 'amount')->first()->value)->toBe('123456')
        ->and(setting('t.amount')->format())->toBe('GH₵ 1,234.56');
});

it('reads seeded donation presets as real integers', function () {
    $this->seed(SettingsSeeder::class);

    expect(setting('donations.presets'))->toBe([5000, 10000, 25000, 50000, 100000])
        ->and(setting('donations.min_amount'))->toBeInstanceOf(Money::class)
        ->and(setting('donations.min_amount')->format())->toBe('GH₵ 5.00');
});

// ── Caching ──────────────────────────────────────────────────────────────────

it('loads the whole table in a single query', function () {
    $this->seed(SettingsSeeder::class);
    $this->settings->flush();

    DB::enableQueryLog();
    setting('general.legal_name');
    setting('contact.email_general');
    setting('donations.presets');
    setting('seo.default_title');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Per-key caching would be four round trips to the `cache` TABLE here,
    // because shared hosting has no Redis. One array is the whole point.
    expect($queries)->toBeLessThanOrEqual(1);
});

it('busts the cache on write', function () {
    $this->settings->set('t.x', 'first');
    expect(setting('t.x'))->toBe('first');

    $this->settings->set('t.x', 'second');
    expect(setting('t.x'))->toBe('second');
});

// ── Public exposure is closed by default ─────────────────────────────────────

it('never exposes a non-public setting to the browser', function () {
    $this->seed(SettingsSeeder::class);

    $public = $this->settings->publicValues();

    expect($public)->toHaveKey('contact.phone_primary')
        ->and($public)->toHaveKey('general.legal_name')
        // The safeguarding address routes confidential reports. It is not
        // public content, and neither are fee internals or the TIN.
        ->and($public)->not->toHaveKey('contact.email_safeguarding')
        ->and($public)->not->toHaveKey('donations.fee_percent')
        ->and($public)->not->toHaveKey('general.tin');
});

it('defaults a new setting to not public', function () {
    $this->settings->set('t.secretish', 'value');

    expect(Setting::where('key', 'secretish')->first()->is_public)->toBeFalse();
});

// ── Encryption ───────────────────────────────────────────────────────────────

it('encrypts a setting marked encrypted and still reads it back', function () {
    $s = Setting::create([
        'group' => 'sms', 'key' => 'api_key', 'type' => SettingType::String,
        'label' => 'SMS API key', 'is_encrypted' => true,
    ]);
    $s->setTypedValue('secret-token-value');
    $s->save();

    expect($s->fresh()->value)->not->toBe('secret-token-value')
        ->and($s->fresh()->typedValue())->toBe('secret-token-value');
});

// ── Seeder behaviour ─────────────────────────────────────────────────────────

it('never overwrites a value the foundation has supplied', function () {
    $this->seed(SettingsSeeder::class);

    $this->settings->set('contact.phone_primary', '+233201112222');

    // Re-running on deploy must add new settings without undoing real work.
    $this->seed(SettingsSeeder::class);

    expect(setting('contact.phone_primary'))->toBe('+233201112222');
});

it('refreshes labels and descriptions on re-run', function () {
    $this->seed(SettingsSeeder::class);

    Setting::where('group', 'general')->where('key', 'legal_name')->update(['label' => 'Stale']);

    $this->seed(SettingsSeeder::class);

    expect(Setting::where('key', 'legal_name')->first()->label)->toBe('Registered legal name');
});

it('records the financial year as starting in January', function () {
    $this->seed(SettingsSeeder::class);

    expect(setting('donations.financial_year_start_month'))->toBe(1);
});
