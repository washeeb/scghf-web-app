<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ScheduledMessage;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\PaymentManager;
use App\Shop\CurrentCart;
use App\Shop\OrderNotifier;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ShippingZoneSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 9 — the checkout, completed
|--------------------------------------------------------------------------
|
| A GHANAIAN ADDRESS. Region, town, area, house or directions, a landmark,
| the GhanaPost GPS code — because "12 Ring Road" finds nobody in Tamale and
| "opposite the filling station, GA-184-3456" finds everybody.
|
| THE GIFT AT THE LAST STEP IS A DONATION, NOT A LINE. It is receipted, it
| moves the General Fund, and it is not on the invoice, which lists goods.
|
| AN ORDER PAGE IS PERSONAL DATA. The signed link from the email, the account
| that placed it, or the browser that placed it — a bare URL is refused.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
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
    ShippingZone::where('slug', 'collection')->update(['is_active' => true, 'pickup_address' => '4 Hospital Road, Bolgatanga', 'pickup_hours' => 'Mon–Fri 9–4']);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

function checkoutMug(int $stock = 10, int $price = 4_500): ProductVariant
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => $price, 'weight_grams' => 400]);
    $variant->restock($stock);

    return $variant->fresh()->load('product');
}

function basketOf(ProductVariant $variant, int $quantity = 1): TestResponse
{
    $response = test()->post(route('shop.cart.add'), ['variant' => $variant->ulid, 'quantity' => $quantity]);

    if ($cookie = $response->getCookie(CurrentCart::COOKIE)) {
        test()->withCookie(CurrentCart::COOKIE, $cookie->getValue());
    }

    return $response;
}

/** @param array<string, mixed> $overrides */
function ghanaCheckout(array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Ama Boateng',
        'customer_email' => 'ama@example.test',
        'customer_phone' => '0241234567',
        'fulfilment' => 'deliver',
        'delivery_region' => 'Greater Accra',
        'delivery_city' => 'Accra',
        'delivery_area' => 'Madina',
        'delivery_address' => 'House 12, Ring Road',
        'delivery_landmark' => 'Opposite the filling station',
        'delivery_gps' => 'GA-184-3456',
        'consent' => '1',
    ], $overrides);
}

// ── The address ─────────────────────────────────────────────────────────────

it('takes the whole Ghanaian address and prints it as one line a courier can use', function () {
    basketOf(checkoutMug());

    $this->post(route('shop.checkout.store'), ghanaCheckout())->assertRedirect();

    $order = Order::first();

    expect($order->delivery_city)->toBe('Accra')
        ->and($order->delivery_landmark)->toBe('Opposite the filling station')
        ->and($order->delivery_gps)->toBe('GA-184-3456')
        ->and($order->deliveryAddressLine())->toBe('House 12, Ring Road, near Opposite the filling station, Madina, Accra, Greater Accra, GA-184-3456');
});

it('refuses a GhanaPost GPS code that is not one, and asks for the town', function () {
    basketOf(checkoutMug());

    $this->post(route('shop.checkout.store'), ghanaCheckout(['delivery_gps' => 'not-a-code']))
        ->assertSessionHasErrors('delivery_gps');

    $this->post(route('shop.checkout.store'), ghanaCheckout(['delivery_city' => '']))
        ->assertSessionHasErrors('delivery_city');

    expect(Order::count())->toBe(0);
});

it('tells a collecting customer where and when, on the page and in the email', function () {
    basketOf(checkoutMug());
    $this->post(route('shop.checkout.store'), ghanaCheckout(['fulfilment' => 'collect']))->assertRedirect();

    $order = Order::first();
    app(FakeGateway::class)->deliverWebhook($order->transaction);

    $this->get($order->trackingUrl())->assertOk()->assertSee('4 Hospital Road, Bolgatanga')->assertSee('Mon–Fri 9–4');

    $message = ScheduledMessage::where('template_key', 'order.confirmation')->first();
    expect($message->payload['delivery_address'])->toContain('4 Hospital Road');
});

// ── The gift at the last step ───────────────────────────────────────────────

it('offers to round the basket up, computed on the server', function () {
    basketOf(checkoutMug(price: 4_650), 2);

    $this->get(route('shop.checkout'))
        ->assertOk()
        ->assertSee('Round up to GH₵ 100.00')
        ->assertSee('name="donation_amount" value="7.00"', escape: false)
        ->assertSee('Add GH₵ 5.00');
});

it('adds the gift to the total, receipts it as a donation, and keeps it off the invoice', function () {
    basketOf(checkoutMug(price: 4_500), 2);

    $this->post(route('shop.checkout.store'), ghanaCheckout(['donation_amount' => '10.00']))->assertRedirect();

    $order = Order::first();

    // 9,000 goods + 2,500 delivery + 1,000 gift.
    expect($order->donation->toMinor())->toBe(1_000)
        ->and($order->total->toMinor())->toBe(12_500)
        ->and($order->goodsTotal()->toMinor())->toBe(11_500)
        ->and($order->transaction->amount->toMinor())->toBe(12_500);

    app(FakeGateway::class)->deliverWebhook($order->transaction);
    app(PaymentManager::class)->verifyAndSettle($order->transaction->fresh());

    $gift = Donation::query()->where('order_id', $order->id)->whereNull('order_item_id')->first();

    expect($gift)->not->toBeNull()
        ->and($gift->amount->toMinor())->toBe(1_000)
        ->and($gift->status)->toBe(DonationStatus::Completed)
        ->and($gift->cause->is_general_fund)->toBeTrue()
        ->and($gift->receipt)->not->toBeNull()
        ->and(Invoice::first()->total->toMinor())->toBe(11_500)
        ->and(Donation::count())->toBe(1);

    // Replayed settlement: still one gift.
    app(PaymentManager::class)->verifyAndSettle($order->transaction->fresh());
    expect(Donation::count())->toBe(1);
});

it('can be switched off', function () {
    setting()->set('shop.offer_gift_at_checkout', false);
    basketOf(checkoutMug());

    $this->get(route('shop.checkout'))->assertOk()->assertDontSee('Add a gift?');
});

// ── Finding an order ────────────────────────────────────────────────────────

it('refuses a bare order URL and opens it for the signed link, the owner, and the browser that placed it', function () {
    basketOf(checkoutMug());
    $this->post(route('shop.checkout.store'), ghanaCheckout());
    $order = Order::first();

    // The browser that placed it, in this session.
    $this->get(route('shop.order', $order))->assertOk();

    // A different browser: refused bare, opened signed.
    $this->flushSession();
    $this->get(route('shop.order', $order))->assertForbidden();
    $this->get($order->trackingUrl())->assertOk();

    // The account that owns it.
    $owner = User::factory()->create();
    $order->forceFill(['user_id' => $owner->id])->save();
    $this->flushSession();
    $this->actingAs($owner)->get(route('shop.order', $order))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('shop.order', $order))->assertForbidden();
});

it('finds a guest order by reference and the email or phone it was placed with, and nothing less', function () {
    basketOf(checkoutMug());
    $this->post(route('shop.checkout.store'), ghanaCheckout());
    $order = Order::first();
    $this->flushSession();

    $this->get(route('shop.track'))->assertOk()->assertSee('Find your order');

    $this->post(route('shop.track.lookup'), ['reference' => $order->reference, 'contact' => 'somebody@else.test'])
        ->assertSessionHasErrors('reference');

    $this->post(route('shop.track.lookup'), ['reference' => 'SCGHF-O-NOPE', 'contact' => 'ama@example.test'])
        ->assertSessionHasErrors('reference');

    $landing = $this->post(route('shop.track.lookup'), ['reference' => strtolower($order->reference), 'contact' => 'AMA@example.test'])
        ->assertRedirect()->headers->get('Location');
    expect($landing)->toContain('signature=');
    $this->get($landing)->assertOk()->assertSee($order->reference);

    $this->post(route('shop.track.lookup'), ['reference' => $order->reference, 'contact' => '+233 24 123 4567'])
        ->assertRedirect();
});

// ── Abandoned checkouts ─────────────────────────────────────────────────────

it('reminds an abandoned checkout only when switched on, only once, and only with consent', function () {
    basketOf(checkoutMug());
    $this->post(route('shop.checkout.store'), ghanaCheckout());
    $order = Order::first();

    // Off by default.
    $order->onPaymentAbandoned($order->transaction);
    expect(ScheduledMessage::where('template_key', 'order.abandoned')->count())->toBe(0);

    // On, but no consent from this address: nothing.
    setting()->set('shop.abandoned_checkout_reminder', true);
    $order->refresh()->onPaymentAbandoned($order->transaction);
    expect(ScheduledMessage::where('template_key', 'order.abandoned')->count())->toBe(0);

    // A donor record that ticked the box: once.
    Donor::factory()->create(['email' => 'ama@example.test', 'consent_email' => true]);
    app(OrderNotifier::class)->abandoned($order->fresh());
    app(OrderNotifier::class)->abandoned($order->fresh());

    $reminders = ScheduledMessage::where('template_key', 'order.abandoned')->get();
    expect($reminders)->toHaveCount(1)
        ->and($reminders->first()->payload['resume_url'])->toBe(route('shop.cart'))
        ->and($order->fresh()->reminded_at)->not->toBeNull();
});
