<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Support\Settings;
use App\Support\SiteCache;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Wave 1 — the live thermometer
|--------------------------------------------------------------------------
|
| /screen/{appeal} for a projector, and the JSON it polls. The numbers are
| the appeal's cached total; the recent gifts are first names, honouring
| anonymity and the donor-wall switch; nothing carries an amount.
*/

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    app(Settings::class)->flush();
    SiteCache::flush();
});

function gift(Cause $cause, string $name, int $minor = 5000, bool $anonymous = false): Donation
{
    $donation = Donation::factory()->create([
        'cause_id' => $cause->getKey(),
        'status' => DonationStatus::Completed->value,
        'donor_name' => $name,
        'is_anonymous' => $anonymous,
        'amount_minor' => $minor,
        'paid_at' => now(),
    ]);

    $cause->recordDonation($donation);

    return $donation;
}

it('shows the total, the goal and the QR code without the site chrome', function () {
    $cause = Cause::factory()->create(['title' => 'Harvest appeal', 'slug' => 'harvest-appeal', 'goal' => 500000]);
    gift($cause, 'Ama Mensah', 123400);

    $this->get('/screen/harvest-appeal')
        ->assertOk()
        ->assertSee('Harvest appeal')
        ->assertSee('GH₵ 1,234.00')
        ->assertSee('GH₵ 5,000.00')
        ->assertSee('25%')
        ->assertSee('Scan to give')
        ->assertSee('<svg', false)
        ->assertSee('data-screen-feed="'.url('/screen/harvest-appeal/feed.json').'"', false)
        ->assertSee('class="dark"', false)
        ->assertSee('noindex', false)
        ->assertDontSee('data-cookie-consent', false)
        ->assertDontSee('<footer', false);
});

it('can be shown light for a bright room', function () {
    $cause = Cause::factory()->create(['slug' => 'bright']);

    $this->get('/screen/bright?theme=light')->assertOk()->assertDontSee('class="dark"', false);
});

it('feeds first names only, honours anonymity and never an amount', function () {
    $cause = Cause::factory()->create(['slug' => 'dinner', 'goal' => 1000000]);
    gift($cause, 'Ama Mensah', 123400);
    gift($cause, 'Kofi Asante', 50000, anonymous: true);

    $response = $this->getJson('/screen/dinner/feed.json')->assertOk();
    $feed = $response->json();

    expect($feed['raised']['minor'])->toBe(173400)
        ->and($feed['raised']['formatted'])->toBe('GH₵ 1,734.00')
        ->and($feed['goal']['minor'])->toBe(1000000)
        ->and($feed['percent'])->toBe(17)
        ->and($feed['count'])->toBe(2)
        ->and(array_column($feed['recent'], 'name'))->toBe(['Anonymous', 'Ama'])
        ->and($response->getContent())->not->toContain('Mensah')
        ->not->toContain('Asante')
        ->not->toContain('1,234')
        ->not->toContain('500.00');

    foreach ($feed['recent'] as $gift) {
        expect($gift)->toHaveKeys(['name', 'at'])->not->toHaveKey('amount');
    }
});

it('shows nobody when the donor wall is switched off', function () {
    app(Settings::class)->set('site.show_donor_wall', false);

    $cause = Cause::factory()->create(['slug' => 'quiet']);
    gift($cause, 'Ama Mensah');

    expect($this->getJson('/screen/quiet/feed.json')->json('recent'))->toBe([]);
});

it('reports a new gift on the next poll', function () {
    $cause = Cause::factory()->create(['slug' => 'climb', 'goal' => 1000000]);
    gift($cause, 'Ama', 100000);

    expect($this->getJson('/screen/climb/feed.json')->json('raised.minor'))->toBe(100000);

    // The cached total is bumped by recordDonation(), which bumps the site
    // generation, which is the feed's cache key.
    gift($cause, 'Kofi', 250000);

    expect($this->getJson('/screen/climb/feed.json')->json('raised.minor'))->toBe(350000)
        ->and($this->getJson('/screen/climb/feed.json')->json('count'))->toBe(2);
});

it('has no goal line for an appeal without a target', function () {
    $cause = Cause::factory()->create(['slug' => 'open', 'goal' => null]);

    expect($this->getJson('/screen/open/feed.json')->json('goal'))->toBeNull();
    $this->get('/screen/open')->assertOk()->assertDontSee('role="progressbar"', false);
});

it('is a 404 for an appeal that is not live', function () {
    Cause::factory()->draft()->create(['slug' => 'draft']);

    $this->get('/screen/draft')->assertNotFound();
    $this->getJson('/screen/draft/feed.json')->assertNotFound();
});

it('is never served from the page cache', function () {
    expect(config('performance.page_cache.except'))->toContain('screen/*');
});
