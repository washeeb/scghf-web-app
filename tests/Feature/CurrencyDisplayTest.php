<?php

declare(strict_types=1);

use App\Models\Cause;
use App\Support\CurrencyDisplay;
use App\Support\ExchangeRates;
use App\Support\Settings;
use App\ValueObjects\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Wave 2 — multi-currency display (roadmap 1.8, display only)
|--------------------------------------------------------------------------
|
| An approximate foreign figure beside a cedi amount, from a daily feed or
| the rates the treasurer types, chosen by the foundation or the visitor.
| Integer arithmetic on pesewas throughout; nothing about charging changes.
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    app(Settings::class)->flush();
    Cache::forget(ExchangeRates::CACHE_KEY);
});

function fakeFeed(float $usdPerCedi = 0.08, float $gbpPerCedi = 0.0625, float $eurPerCedi = 0.075): void
{
    Http::fake([
        'open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['USD' => $usdPerCedi, 'GBP' => $gbpPerCedi, 'EUR' => $eurPerCedi, 'GHS' => 1]], 200),
    ]);
}

it('fetches the feed and keeps cedis per unit as scaled integers', function () {
    fakeFeed();

    $set = app(ExchangeRates::class)->refresh();

    expect($set)->not->toBeNull()
        ->and($set['rates'])->toBe(['USD' => 125000, 'GBP' => 160000, 'EUR' => 133333])
        ->and($set['source'])->toBe('api')
        ->and(Cache::get(ExchangeRates::CACHE_KEY)['rates']['USD'])->toBe(125000);
});

it('keeps the last rates when the feed fails', function () {
    Http::fakeSequence('open.er-api.com/*')
        ->push(['result' => 'success', 'rates' => ['USD' => 0.08, 'GBP' => 0.0625, 'EUR' => 0.075]], 200)
        ->push('', 503)
        ->push('', 503);

    app(ExchangeRates::class)->refresh();

    expect(app(ExchangeRates::class)->refresh())->toBeNull()
        ->and(app(ExchangeRates::class)->rate('USD'))->toBe(125000);

    $this->artisan('scghf:refresh-rates')->assertFailed();
});

it('converts with integer arithmetic and marks the figure approximate', function () {
    fakeFeed();
    app(ExchangeRates::class)->refresh();

    $display = app(CurrencyDisplay::class);

    expect($display->convert(Money::ofMinor(15000), 'USD')?->toMinor())->toBe(1200)   // GH₵ 150 at 12.5 → $12.00
        ->and($display->approx(Money::ofMinor(15000), 'USD'))->toBe('≈ $ 12.00')
        ->and($display->approx(Money::ofMinor(15000), 'GBP'))->toBe('≈ £ 9.38')
        ->and($display->approx(Money::ofMinor(5_000_000), 'USD'))->toBe('≈ $ 4,000')   // whole units above 100
        ->and($display->convert(Money::ofMinor(100, 'USD'), 'USD'))->toBeNull()        // only cedis are converted
        ->and($display->approx(Money::ofMinor(15000), null))->toBeNull();               // no currency chosen
});

it('uses the manual rates when the source is manual, or when the feed has never answered', function () {
    app(Settings::class)->set('currency.rate_usd', '12.00');

    expect(app(ExchangeRates::class)->current()['source'])->toBe('manual')
        ->and(app(CurrencyDisplay::class)->approx(Money::ofMinor(12000), 'USD'))->toBe('≈ $ 10.00');

    fakeFeed();
    app(ExchangeRates::class)->refresh();
    expect(app(ExchangeRates::class)->current()['source'])->toBe('api');

    app(Settings::class)->set('currency.rate_source', 'manual');
    expect(app(ExchangeRates::class)->current()['source'])->toBe('manual')
        ->and(app(ExchangeRates::class)->rate('GBP'))->toBeNull();
});

it('shows nothing, and no picker, when there is no rate at all', function () {
    Cause::factory()->create(['slug' => 'no-rates', 'goal' => 500000]);

    expect(app(ExchangeRates::class)->current())->toBeNull();

    $this->get(route('causes.show', 'no-rates'))->assertOk()->assertDontSee('data-approx', false)->assertDontSee('data-currency-picker', false);
});

it('shows the foundation default and lets the visitor choose another in the footer', function () {
    fakeFeed();
    app(ExchangeRates::class)->refresh();
    app(Settings::class)->set('currency.display_default', 'GBP');
    Cause::factory()->create(['slug' => 'harvest', 'goal' => 500000]);

    $this->get(route('causes.show', 'harvest'))
        ->assertOk()
        ->assertSee('data-currency-picker', false)
        ->assertSee('≈ £ 312')        // GH₵ 5,000 goal at 16 → £312.50, shown whole
        ->assertSee('GH₵ 5,000.00')   // the cedi figure stays
        ->assertSee('Approximate; gifts are taken in cedis.');

    $this->withUnencryptedCookie(CurrencyDisplay::COOKIE, 'USD')->get(route('causes.show', 'harvest'))
        ->assertOk()->assertSee('≈ $ 400')->assertDontSee('≈ £');

    $this->withUnencryptedCookie(CurrencyDisplay::COOKIE, 'none')->get(route('causes.show', 'harvest'))
        ->assertOk()->assertDontSee('data-approx', false);
});

it('sets the cookie from the picker and refuses to redirect off the site', function () {
    $this->post(route('currency.set'), ['currency' => 'usd', 'return' => url('/give')])
        ->assertRedirect(url('/give'))
        ->assertCookie(CurrencyDisplay::COOKIE, 'USD', encrypted: false);

    $this->post(route('currency.set'), ['currency' => 'XXX', 'return' => 'https://evil.example/phish'])
        ->assertRedirect('/')
        ->assertCookie(CurrencyDisplay::COOKIE, 'none', encrypted: false);
});

it('reports the refreshed rates from the console', function () {
    fakeFeed();

    $this->artisan('scghf:refresh-rates')
        ->expectsOutputToContain('1 USD = GH₵ 12.5000')
        ->assertSuccessful();
});
