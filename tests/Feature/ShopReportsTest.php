<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Pages\ShopReportsPage;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\TicketsRelationManager;
use App\Models\Cart;
use App\Models\Cause;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\IssuedTicket;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Policies\BasePolicy;
use App\Shop\CheckoutService;
use App\Shop\OrderFulfilment;
use App\Shop\ShopReports;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ShippingZoneSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 — the shop's numbers
|--------------------------------------------------------------------------
|
| GOODS ARE NOT GIFTS. A sponsored meal sold through the shop is a donation
| — in the giving reports — and never in the shop revenue as well, so the
| one line that adds donations to net shop proceeds counts nothing twice.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(ShippingZoneSeeder::class);

    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    $zone = ShippingZone::where('slug', 'greater-accra')->firstOrFail();
    $zone->update(['is_active' => true]);
    ShippingRate::create(['shipping_zone_id' => $zone->id, 'name' => 'Standard', 'price' => 2_500]);

    BasePolicy::forgetKnownPermissions();
    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** @param array<ProductVariant|array{0: ProductVariant, 1: int}> $lines */
function paidOrderOf(array $lines, array $details = []): Order
{
    $cart = Cart::create([]);

    foreach ($lines as $line) {
        [$variant, $quantity] = is_array($line) ? $line : [$line, 1];
        $cart->add($variant->fresh(), $quantity);
    }

    $order = app(CheckoutService::class)->createOrder($cart->fresh()->load('items.variant.product'), array_merge([
        'customer_name' => 'Ama Boateng',
        'customer_email' => 'ama@example.test',
        'customer_phone' => '+233241234567',
        'delivery_address' => 'House 12',
        'delivery_city' => 'Accra',
        'delivery_region' => 'Greater Accra',
    ], $details));

    app(PaymentManager::class)->charge($order, ['email' => $order->customer_email]);
    app(PaymentManager::class)->verifyAndSettle($order->fresh()->transaction);
    app(OrderFulfilment::class)->fulfil($order->fresh());

    return $order->fresh();
}

function reportVariant(array $product = [], int $price = 4_500, int $stock = 20): ProductVariant
{
    $p = Product::factory()->create($product);
    $v = ProductVariant::factory()->create(['product_id' => $p->id, 'price' => $price, 'weight_grams' => 100]);

    if ($p->requiresDelivery()) {
        $v->restock($stock);
    }

    return $v->fresh()->load('product');
}

it('reports goods, delivery, fees and net proceeds, with the gifts kept apart and refunds on their own line', function () {
    $cause = Cause::query()->where('is_general_fund', false)->first() ?? Cause::first();
    $mugs = ProductCategory::factory()->create(['name' => 'Mugs']);
    $bags = ProductCategory::factory()->create(['name' => 'Bags']);

    $mug = reportVariant(['name' => 'Mug', 'product_category_id' => $mugs->id, 'cause_id' => $cause->id], price: 4_500);
    $bag = reportVariant(['name' => 'Bag', 'product_category_id' => $bags->id], price: 10_000);
    $meal = reportVariant(['name' => 'Sponsor a meal', 'product_type' => Product::TYPE_DONATION, 'cause_id' => $cause->id], price: 2_000);

    // Order A: 2 mugs + 1 meal + a GH₵ 5 round-up. Order B: 1 bag, later refunded.
    $a = paidOrderOf([[$mug, 2], [$meal, 1]], ['donation' => '5.00']);
    $b = paidOrderOf([$bag]);
    $b->forceFill(['status' => OrderStatus::Refunded])->save();

    $reports = ShopReports::between(now()->startOfMonth(), now());
    $summary = $reports->summary();

    expect($summary['orders'])->toBe(1)
        ->and($summary['goods']->toMinor())->toBe(9_000)
        ->and($summary['delivery']->toMinor())->toBe(2_500)
        ->and($summary['gifts']->toMinor())->toBe(2_500)
        ->and($summary['fees']->toMinor())->toBe($a->fee->toMinor())
        ->and($summary['net']->toMinor())->toBe(9_000 + 2_500 - $a->fee->toMinor())
        ->and($summary['refunded']->toMinor())->toBe(12_500)
        ->and($reports->byProduct()->pluck('label')->all())->toBe(['Mug'])
        ->and($reports->byCategory()->first()['label'])->toBe('Mugs')
        ->and($reports->bestSellers()->first()['quantity'])->toBe(2)
        ->and($reports->byCause()->first()['label'])->toBe($cause->title)
        ->and($reports->byCause()->first()['goods']->toMinor())->toBe(9_000)
        ->and($reports->byPeriod('month')->first()['goods']->toMinor())->toBe(9_000);
});

it('adds donations to net shop proceeds once', function () {
    $cause = Cause::first();
    $mug = reportVariant(['name' => 'Mug'], price: 4_500);
    $meal = reportVariant(['name' => 'Sponsor a meal', 'product_type' => Product::TYPE_DONATION, 'cause_id' => $cause->id], price: 2_000);

    $order = paidOrderOf([$mug, $meal], ['donation' => '5.00']);

    $funds = ShopReports::between(now()->startOfMonth(), now())->fundsRaised();

    // The meal (2,000) and the round-up (500) are donations; the mug is goods.
    expect($funds['donations']->toMinor())->toBe(2_500)
        ->and($funds['shop']->toMinor())->toBe(4_500 + 2_500 - $order->fee->toMinor())
        ->and($funds['total']->toMinor())->toBe($funds['donations']->toMinor() + $funds['shop']->toMinor());
});

it('values the shelf at selling price, physical stock only', function () {
    reportVariant(['name' => 'Mug'], price: 4_500, stock: 10);
    reportVariant(['name' => 'Report PDF', 'product_type' => Product::TYPE_DIGITAL], price: 1_000);

    $stock = ShopReports::between(now()->startOfMonth(), now())->stockValuation();

    expect($stock['units'])->toBe(10)
        ->and($stock['variants'])->toBe(1)
        ->and($stock['value']->toMinor())->toBe(45_000);
});

it('draws the reports page for somebody who may see orders', function () {
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['orders.view']);
    $this->actingAs($user);

    paidOrderOf([reportVariant(['name' => 'Mug'], price: 4_500)]);

    Livewire::test(ShopReportsPage::class)
        ->assertOk()
        ->assertSee('GH₵ 45.00')
        ->assertSee('Total funds raised')
        ->call('setRange', 'year')
        ->assertSet('granularity', 'month');

    expect(ShopReportsPage::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->staff()->withTwoFactor()->create());
    expect(ShopReportsPage::canAccess())->toBeFalse();
});

// ── The door ────────────────────────────────────────────────────────────────

it('checks a ticket in by its code, once', function () {
    config(['features.event_ticketing' => true]);

    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(['events.view', 'events.manage', 'events.view_registrations']);
    $this->actingAs($user);

    $event = Event::factory()->create(['is_ticketed' => true]);
    $type = EventTicket::factory()->create(['event_id' => $event->id]);
    $ticketProduct = reportVariant(['name' => 'Gala ticket', 'product_type' => Product::TYPE_TICKET, 'event_ticket_id' => $type->id], price: 5_000);

    paidOrderOf([[$ticketProduct, 2]]);
    $ticket = IssuedTicket::first();

    Livewire::test(TicketsRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
        ->assertOk()
        ->assertSee($ticket->code)
        ->callAction(TestAction::make('checkIn')->table($ticket));

    expect($ticket->fresh()->checked_in_at)->not->toBeNull()
        ->and($ticket->fresh()->checked_in_by)->toBe($user->id);

    expect(fn () => $ticket->fresh()->checkIn($user))->toThrow(RuntimeException::class, 'already used');
});
