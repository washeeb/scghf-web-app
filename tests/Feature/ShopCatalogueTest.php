<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Enums\OrderStatus;
use App\Media\MediaLibrary;
use App\Models\Cart;
use App\Models\Cause;
use App\Models\DigitalDownloadToken;
use App\Models\Donation;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventTicket;
use App\Models\IssuedTicket;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ScheduledMessage;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Shop\CheckoutService;
use App\Shop\OrderFulfilment;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CauseSeeder;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ShippingZoneSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 — the catalogue, completed
|--------------------------------------------------------------------------
|
| FOUR KINDS OF PRODUCT, FOUR CONSEQUENCES OF PAYING. A mug is packed. A
| download is a token that expires and counts. A sponsored meal becomes a
| donation — receipted, counted as giving, and never counted again as shop
| sales. A ticket is a code at the door. Each of those is issued exactly once
| however many times the settlement is replayed.
|
| PRICES ARE READ LIVE AND SNAPSHOTTED ON THE ORDER. Bulk tiers and the
| signed-in price are decided by the basket at checkout and written onto the
| line, so the order's own lines add up to what was charged.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(ShippingZoneSeeder::class);
    $this->seed(CmsReferenceSeeder::class);

    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');
    setting()->set('contact.email_shop', 'shop@example.test');
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    $zone = ShippingZone::where('slug', 'greater-accra')->firstOrFail();
    $zone->update(['is_active' => true]);
    ShippingRate::create(['shipping_zone_id' => $zone->id, 'name' => 'Standard', 'price' => 2_500]);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** @param array<string, mixed> $product @param array<string, mixed> $variant */
function catalogueItem(array $product = [], array $variant = [], int $stock = 10): ProductVariant
{
    $p = Product::factory()->create($product);
    $v = ProductVariant::factory()->create(array_merge(['product_id' => $p->id, 'price' => 4_500, 'weight_grams' => 300], $variant));

    if ($stock > 0 && $p->requiresDelivery()) {
        $v->restock($stock);
    }

    return $v->fresh()->load('product');
}

function catalogueCheckout(Cart $cart, array $details = []): Order
{
    return app(CheckoutService::class)->createOrder($cart->fresh()->load('items.variant.product'), array_merge([
        'customer_name' => 'Ama Boateng',
        'customer_email' => 'ama@example.test',
        'customer_phone' => '+233241234567',
        'delivery_address' => '12 Ring Road',
        'delivery_region' => 'Greater Accra',
    ], $details));
}

function payFor(Order $order): Order
{
    $order->refresh();
    app(PaymentManager::class)->charge($order, ['email' => $order->customer_email]);
    app(PaymentManager::class)->verifyAndSettle($order->fresh()->transaction);

    return $order->fresh();
}

// ── Pricing ─────────────────────────────────────────────────────────────────

it('prices by quantity break and gives a signed-in customer the lower of the two', function () {
    $variant = catalogueItem(variant: [
        'price' => 5_000,
        'member_price' => 4_600,
        'price_tiers' => [['min_quantity' => 10, 'price_minor' => 4_000], ['min_quantity' => 5, 'price_minor' => 4_500]],
    ]);

    expect($variant->priceFor(1)->toMinor())->toBe(5_000)
        ->and($variant->priceFor(5)->toMinor())->toBe(4_500)
        ->and($variant->priceFor(12)->toMinor())->toBe(4_000)
        ->and($variant->priceFor(1, member: true)->toMinor())->toBe(4_600)
        ->and($variant->priceFor(12, member: true)->toMinor())->toBe(4_000);
});

it('writes the basket price onto the order line so the lines add up to the charge', function () {
    $variant = catalogueItem(variant: ['price' => 5_000, 'price_tiers' => [['min_quantity' => 5, 'price_minor' => 4_000]]], stock: 20);

    $cart = Cart::create([]);
    $cart->add($variant, 6);
    $order = catalogueCheckout($cart);

    expect($order->items->first()->unit_price->toMinor())->toBe(4_000)
        ->and($order->subtotal->toMinor())->toBe(24_000);
});

it('applies the signed-in price to a basket that belongs to an account', function () {
    $variant = catalogueItem(variant: ['price' => 5_000, 'member_price' => 4_000]);
    $user = User::factory()->create();

    $guest = Cart::create([]);
    $guest->add($variant, 1);
    $owned = Cart::create(['user_id' => $user->id]);
    $owned->add($variant, 1);

    expect($guest->fresh()->load('items.variant')->subtotal()->toMinor())->toBe(5_000)
        ->and($owned->fresh()->load('items.variant')->subtotal()->toMinor())->toBe(4_000);
});

// ── The product page ────────────────────────────────────────────────────────

it('shows specifications, quantity breaks and the editor-chosen related products', function () {
    $variant = catalogueItem(
        ['specifications' => ['Material' => 'Cotton', 'Made in' => 'Bolgatanga']],
        ['price' => 5_000, 'price_tiers' => [['min_quantity' => 10, 'price_minor' => 4_000]]],
    );
    $other = catalogueItem(['name' => 'Bongo Basket']);
    $variant->product->related()->attach($other->product_id, ['sort_order' => 1]);

    $this->get(route('shop.show', $variant->product))
        ->assertOk()
        ->assertSee('Bolgatanga')
        ->assertSee('10 or more: GH₵ 40.00 each')
        ->assertSee('Bongo Basket');
});

// ── Digital ─────────────────────────────────────────────────────────────────

it('delivers a download by an expiring, counted link — once, however often settlement is replayed', function () {
    Storage::fake('downloads');
    $media = app(MediaLibrary::class)->add(UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'), null, [], null, private: true);

    $variant = catalogueItem(['product_type' => Product::TYPE_DIGITAL, 'download_media_id' => $media->id, 'download_limit' => 2, 'download_days' => 7], stock: 0);

    $cart = Cart::create([]);
    $cart->add($variant, 1);
    $order = catalogueCheckout($cart, ['delivery_address' => null, 'delivery_region' => null]);

    expect($order->shipping->toMinor())->toBe(0)->and($order->requiresDelivery())->toBeFalse();

    $order = payFor($order);
    app(PaymentManager::class)->verifyAndSettle($order->transaction);
    app(OrderFulfilment::class)->fulfil($order->fresh());

    expect(DigitalDownloadToken::count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and(ScheduledMessage::where('template_key', 'order.download')->count())->toBe(1);

    $token = DigitalDownloadToken::first();
    expect($token->max_downloads)->toBe(2)->and($token->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();

    $this->get(route('shop.download', $token))->assertOk()->assertDownload();
    $this->get(route('shop.download', $token))->assertOk();
    $this->get(route('shop.download', $token))->assertOk()->assertSee('maximum number of times');

    expect($token->fresh()->download_count)->toBe(2);
});

it('refuses a download for an order that was never paid', function () {
    Storage::fake('downloads');
    $media = app(MediaLibrary::class)->add(UploadedFile::fake()->create('report.pdf', 10, 'application/pdf'), null, [], null, private: true);
    $variant = catalogueItem(['product_type' => Product::TYPE_DIGITAL, 'download_media_id' => $media->id], stock: 0);

    $cart = Cart::create([]);
    $cart->add($variant, 1);
    $order = catalogueCheckout($cart, ['delivery_address' => null, 'delivery_region' => null]);

    $token = DigitalDownloadToken::create(['order_id' => $order->id, 'order_item_id' => $order->items->first()->id, 'media_id' => $media->id]);

    $this->get(route('shop.download', $token))->assertOk()->assertSee('has not been paid for');
    expect($token->fresh()->download_count)->toBe(0);
});

it('keeps a paid file off the public disk', function () {
    Storage::fake('downloads');
    $media = app(MediaLibrary::class)->add(UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf'), null, [], null, private: true);

    expect($media->disk)->toBe('downloads')->and($media->collection_name)->toBe('private');
});

// ── Donation products ───────────────────────────────────────────────────────

it('turns a paid "sponsor a meal" line into a receipted donation to the appeal, exactly once', function () {
    $cause = Cause::query()->where('is_general_fund', false)->first() ?? Cause::first();
    $variant = catalogueItem(['product_type' => Product::TYPE_DONATION, 'cause_id' => $cause->id, 'name' => 'Sponsor a meal'], ['price' => 2_000], stock: 0);

    $cart = Cart::create([]);
    $cart->add($variant, 3);
    $order = catalogueCheckout($cart, ['delivery_address' => null, 'delivery_region' => null]);

    expect($variant->fresh()->tracks_stock)->toBeFalse();

    $order = payFor($order);
    app(OrderFulfilment::class)->fulfil($order);
    app(OrderFulfilment::class)->fulfil($order->fresh());

    $donation = Donation::query()->where('order_id', $order->id)->first();

    expect(Donation::count())->toBe(1)
        ->and($donation->amount->toMinor())->toBe(6_000)
        ->and($donation->status)->toBe(DonationStatus::Completed)
        ->and($donation->cause_id)->toBe($cause->id)
        ->and($donation->source)->toBe('shop')
        ->and($donation->receipt)->not->toBeNull()
        ->and($cause->fresh()->raisedAmount()->toMinor())->toBe(6_000)
        ->and($donation->donor->email)->toBe('ama@example.test')
        ->and(ScheduledMessage::where('template_key', 'donation.receipt')->count())->toBe(1);
});

// ── Tickets ─────────────────────────────────────────────────────────────────

it('issues one ticket code per admission and registers the buyer, once', function () {
    config(['features.event_ticketing' => true]);

    $event = Event::factory()->create(['is_ticketed' => true]);
    $type = EventTicket::factory()->create(['event_id' => $event->id, 'quantity' => 50]);
    $variant = catalogueItem(['product_type' => Product::TYPE_TICKET, 'event_ticket_id' => $type->id], ['price' => 5_000], stock: 0);

    $cart = Cart::create([]);
    $cart->add($variant, 3);
    $order = catalogueCheckout($cart, ['delivery_address' => null, 'delivery_region' => null]);
    $order = payFor($order);
    app(OrderFulfilment::class)->fulfil($order);
    app(OrderFulfilment::class)->fulfil($order->fresh());

    expect(IssuedTicket::count())->toBe(3)
        ->and(IssuedTicket::pluck('code')->unique())->toHaveCount(3)
        ->and(EventRegistration::where('event_id', $event->id)->count())->toBe(1)
        ->and(EventRegistration::first()->guests)->toBe(2)
        ->and($type->fresh()->sold)->toBe(3)
        ->and($event->fresh()->registered_count)->toBe(3)
        ->and(ScheduledMessage::where('template_key', 'order.tickets')->count())->toBe(1);
});

// ── Low stock ───────────────────────────────────────────────────────────────

it('tells the shop email once about each item that has run low, until it is restocked', function () {
    setting()->set('shop.low_stock_threshold', 3);
    $low = catalogueItem(['name' => 'Tote bag'], stock: 2);
    catalogueItem(['name' => 'Plenty'], stock: 50);

    $this->artisan('scghf:stock-alerts --execute')->assertSuccessful();

    $message = ScheduledMessage::where('template_key', 'stock.low')->first();
    expect($message)->not->toBeNull()
        ->and($message->to_address)->toBe('shop@example.test')
        ->and(json_encode($message->payload))->toContain('Tote bag')->not->toContain('Plenty');

    // Tomorrow: nothing new to say.
    $this->travel(1)->day();
    $this->artisan('scghf:stock-alerts --execute')->assertSuccessful();
    expect(ScheduledMessage::where('template_key', 'stock.low')->count())->toBe(1);

    // Restocked and sold down again: news again.
    $low->adjust(10, 'Delivery from the printer.');
    expect($low->fresh()->low_stock_alerted_at)->toBeNull();
    $low->fresh()->adjust(-10, 'Damaged in the flood.');

    $this->travel(1)->day();
    $this->artisan('scghf:stock-alerts --execute')->assertSuccessful();
    expect(ScheduledMessage::where('template_key', 'stock.low')->count())->toBe(2);
});
