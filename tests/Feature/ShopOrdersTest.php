<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\DigitalDownloadToken;
use App\Models\DonationReceipt;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Payments\ReconciliationService;
use App\Shop\CheckoutService;
use App\Shop\InvoiceIssuer;
use App\Support\Acknowledgement;
use App\Support\TaxDeductibility;
use App\ValueObjects\Money;
use Database\Seeders\ShippingZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The shop: checkout, orders and invoices
|--------------------------------------------------------------------------
|
| The rule this module exists to keep is the compliance one: shop sales and
| charitable donations have separate accounting, receipts, payment types and
| reporting, and a charitable acknowledgement is NEVER issued for a purchase.
|
| What is SHARED is the gateway boundary, because two payment paths is how a
| ledger diverges. What is SEPARATE is everything after it — a different
| document, on a different numbering series, saying explicitly what it is not.
|
*/

beforeEach(function () {
    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    $this->checkout = app(CheckoutService::class);
    $this->invoices = app(InvoiceIssuer::class);
    $this->payments = app(PaymentManager::class);

    // Accra, with a standard rate. Seeded zones are inactive by design, so a
    // test that needs delivery has to switch one on — which is the same thing
    // the foundation will have to do.
    $this->seed(ShippingZoneSeeder::class);
    $this->zone = ShippingZone::where('slug', 'greater-accra')->first();
    $this->zone->update(['is_active' => true]);
    $this->rate = ShippingRate::create([
        'shipping_zone_id' => $this->zone->id,
        'name' => 'Standard',
        'price' => 2_500,
        'estimated_days' => '2-3 days',
    ]);
});

/** A basket with one mug in it. */
function basketWith(int $quantity = 2, int $stock = 10, int $price = 4_500): Cart
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create([
        'product_id' => $product->id,
        'price' => $price,
        'weight_grams' => 400,
    ]);
    $variant->restock($stock);

    $cart = Cart::create([]);
    $cart->add($variant->fresh(), $quantity);

    return $cart->fresh()->load('items.variant.product');
}

/** @param array<string, mixed> $overrides */
function checkoutDetails(array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Ama Boateng',
        'customer_email' => 'ama@example.com',
        'customer_phone' => '+233241234567',
        'delivery_address' => '12 Ring Road',
        'delivery_region' => 'Greater Accra',
    ], $overrides);
}

function settleOrder(Order $order): void
{
    app(PaymentManager::class)->verifyAndSettle($order->fresh()->transaction);
}

// ═══════════════════════════════════════════════════════════════════════════
//  The separation rule
// ═══════════════════════════════════════════════════════════════════════════

it('never issues a charitable acknowledgement for a purchase', function () {
    $order = Order::factory()->paid()->create();

    expect(app(TaxDeductibility::class)->mayAcknowledge($order))->toBeFalse();
});

it('throws if an order is ever passed to the acknowledgement builder', function () {
    app(Acknowledgement::class)->for([
        'payable' => Order::factory()->paid()->create(),
        'amount' => Money::ofMajor('50.00'),
        'donor_name' => 'Ama',
        'donated_on' => now(),
        'cause' => 'x',
        'receipt_number' => 'x',
        'payment_reference' => 'x',
    ]);
})->throws(RuntimeException::class, 'never be issued');

it('puts invoices on their own series, never the donation one', function () {
    $order = Order::factory()->paid()->create();

    $invoice = $this->invoices->issue($order);

    expect($invoice->invoice_number)->toStartWith('SCGHF-INV-')
        ->and($invoice->usesSalesSeries())->toBeTrue()
        // The two series must never interleave.
        ->and($invoice->invoice_number)->not->toStartWith('SCGHF-R-')
        ->and(DonationReceipt::count())->toBe(0);
});

it('prints on every invoice that it is not a donation acknowledgement', function () {
    // The customer holding it is the person most likely to assume otherwise at
    // tax time. Saying so on the document is cheaper than explaining it after.
    $invoice = $this->invoices->issue(Order::factory()->paid()->create());

    expect($invoice->statement)
        ->toContain('NOT an acknowledgement of a charitable contribution')
        ->toContain('section 100');
});

it('numbers invoices independently of acknowledgements', function () {
    $orderA = Order::factory()->paid()->create();
    $orderB = Order::factory()->paid()->create();

    $year = now()->year;

    expect($this->invoices->issue($orderA)->invoice_number)
        ->toBe(sprintf('SCGHF-INV-%d-000001', $year))
        ->and($this->invoices->issue($orderB)->invoice_number)
        ->toBe(sprintf('SCGHF-INV-%d-000002', $year));
});

it('refuses to invoice an order nobody has paid for', function () {
    $this->invoices->issue(Order::factory()->create());
})->throws(RuntimeException::class, 'Only a paid order');

it('never issues two invoices for the same order', function () {
    $order = Order::factory()->paid()->create();

    expect($this->invoices->issue($order)->id)
        ->toBe($this->invoices->issue($order->fresh())->id)
        ->and(Invoice::count())->toBe(1);
});

it('refuses to alter an issued invoice', function () {
    $this->invoices->issue(Order::factory()->paid()->create())->update(['total' => 1]);
})->throws(RuntimeException::class, 'cannot be altered');

// ═══════════════════════════════════════════════════════════════════════════
//  Checkout
// ═══════════════════════════════════════════════════════════════════════════

it('prices the basket live and snapshots it onto the order', function () {
    $cart = basketWith(quantity: 2, price: 4_500);

    $order = $this->checkout->createOrder($cart, checkoutDetails());

    expect($order->subtotal)->toEqualPesewas(9_000)
        ->and($order->shipping)->toEqualPesewas(2_500)
        ->and($order->total)->toEqualPesewas(11_500)
        ->and($order->items)->toHaveCount(1)
        ->and($order->items->first()->unit_price)->toEqualPesewas(4_500)
        ->and($order->items->first()->line_total)->toEqualPesewas(9_000);
});

it('keeps the snapshot when the product price later changes', function () {
    // A price change must not retroactively alter what a customer paid.
    $cart = basketWith(price: 4_500);
    $order = $this->checkout->createOrder($cart, checkoutDetails());

    $cart->items->first()->variant->update(['price' => 9_900]);

    expect($order->fresh()->items->first()->unit_price)->toEqualPesewas(4_500);
});

it('survives the product being discontinued entirely', function () {
    $cart = basketWith();
    $order = $this->checkout->createOrder($cart, checkoutDetails());
    $variant = $cart->items->first()->variant;

    $variant->forceDelete();

    // The snapshot stands on its own, so the invoice still prints.
    expect($order->fresh()->items->first()->product_name)->not->toBeEmpty()
        ->and($order->fresh()->items->first()->sku)->not->toBeEmpty()
        ->and($order->fresh()->items->first()->product_variant_id)->toBeNull();
});

it('derives the line total rather than trusting what it is given', function () {
    // Accepting a line total from the caller would let a request set its own
    // price.
    $order = Order::factory()->create();

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_name' => 'Mug',
        'sku' => 'X1',
        'quantity' => 3,
        'unit_price' => 1_000,
        'line_total' => 1,
    ]);

    expect($item->fresh()->line_total)->toEqualPesewas(3_000);
});

it('refuses to check out an empty basket', function () {
    $this->checkout->createOrder(Cart::create([]), checkoutDetails());
})->throws(RuntimeException::class, 'nothing in the basket');

it('refuses to check out something no longer available', function () {
    // Better on the basket page than at the gateway, and far better than after
    // the money is taken.
    $cart = basketWith(quantity: 2, stock: 1);

    $this->checkout->createOrder($cart, checkoutDetails());
})->throws(RuntimeException::class, 'no longer available');

it('refuses to check out a product pulled for regulatory review', function () {
    $cart = basketWith();
    $cart->items->first()->variant->product->update(['description' => 'Contains a herbal remedy.']);

    $this->checkout->createOrder($cart->fresh()->load('items.variant.product'), checkoutDetails());
})->throws(RuntimeException::class, 'no longer available');

it('reconciles the arithmetic before the gateway is called', function () {
    $order = Order::factory()->create(['subtotal' => 9_000, 'shipping' => 2_500, 'total' => 99_999]);

    $order->assertTotalsReconcile();
})->throws(RuntimeException::class, 'does not reconcile');

it('catches lines that do not sum to the subtotal', function () {
    $order = Order::factory()->create(['subtotal' => 9_000, 'shipping' => 2_500, 'total' => 11_500]);
    OrderItem::create([
        'order_id' => $order->id, 'product_name' => 'Mug', 'sku' => 'X1',
        'quantity' => 1, 'unit_price' => 1_000,
    ]);

    $order->load('items')->assertTotalsReconcile();
})->throws(RuntimeException::class, 'lines sum to');

// ═══════════════════════════════════════════════════════════════════════════
//  Shipping
// ═══════════════════════════════════════════════════════════════════════════

it('has zones that between them cover all sixteen Ghanaian regions', function () {
    // A customer in Savannah should not find their region missing from the map
    // entirely. Whether the foundation has switched that zone on yet is a
    // separate, commercial question — which is what unservedRegions() answers.
    ShippingZone::query()->update(['is_active' => true]);

    expect(ShippingZone::unservedRegions())->toBe([]);
});

it('reports regions no ACTIVE zone serves, so the gap is visible', function () {
    // Only Greater Accra is switched on in this suite's setup, so everywhere
    // else is currently unserved — and that is the honest answer to give an
    // administrator looking at the shop.
    expect(ShippingZone::unservedRegions())
        ->toContain('Savannah')
        ->not->toContain('Greater Accra');
});

it('seeds zones inactive, because a price nobody agreed is worse than none', function () {
    ShippingZone::query()->update(['is_active' => false]);
    $this->seed(ShippingZoneSeeder::class);

    expect(ShippingZone::where('is_active', true)->count())->toBe(0);
});

it('refuses an order to a region the shop does not serve yet', function () {
    $cart = basketWith();

    $this->checkout->createOrder($cart, checkoutDetails(['delivery_region' => 'Savannah']));
})->throws(RuntimeException::class, 'does not currently deliver');

it('charges nothing for collection', function () {
    ShippingZone::where('slug', 'collection')->update(['is_active' => true]);
    $cart = basketWith();

    $order = $this->checkout->createOrder($cart, checkoutDetails(['is_pickup' => true]));

    expect($order->shipping)->toEqualPesewas(0)
        ->and($order->is_pickup)->toBeTrue()
        ->and($order->total)->toEqualPesewas(9_000);
});

it('applies a free-delivery threshold against the subtotal, not the total', function () {
    // Otherwise the delivery charge counts towards qualifying for free
    // delivery, which is circular.
    $this->rate->update(['free_above' => 10_000]);

    expect($this->rate->fresh()->priceFor(Money::ofMinor(9_000)))->toEqualPesewas(2_500)
        ->and($this->rate->fresh()->priceFor(Money::ofMinor(10_000)))->toEqualPesewas(0);
});

it('falls through to a rate that covers the weight', function () {
    $this->rate->update(['max_weight_grams' => 100]);
    ShippingRate::create([
        'shipping_zone_id' => $this->zone->id,
        'name' => 'Heavy',
        'price' => 6_000,
        'min_weight_grams' => 101,
    ]);

    $cart = basketWith(quantity: 2);   // 800g

    $order = $this->checkout->createOrder($cart, checkoutDetails());

    expect($order->shipping)->toEqualPesewas(6_000)
        ->and($order->shipping_method)->toBe('Heavy');
});

it('snapshots the shipping method so a later rename changes nothing', function () {
    $cart = basketWith();
    $order = $this->checkout->createOrder($cart, checkoutDetails());

    $this->rate->update(['name' => 'Standard (revised)']);

    expect($order->fresh()->shipping_method)->toBe('Standard');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Coupons
// ═══════════════════════════════════════════════════════════════════════════

it('applies a percentage discount without a float', function () {
    $coupon = Coupon::create(['code' => 'friends10', 'discount_value' => 1000]);   // 10%

    expect($coupon->discountFor(Money::ofMinor(9_000)))->toEqualPesewas(900)
        // Uppercased, so FRIENDS10 and friends10 are one coupon.
        ->and($coupon->code)->toBe('FRIENDS10');
});

it('caps a percentage discount where a ceiling is set', function () {
    // "20% off" on an unusually large order is a discount nobody signed off.
    $coupon = Coupon::create([
        'code' => 'BIG20', 'discount_value' => 2000, 'maximum_discount' => 5_000,
    ]);

    expect($coupon->discountFor(Money::ofMinor(100_000)))->toEqualPesewas(5_000);
});

it('never discounts more than the basket is worth', function () {
    // orders.total_minor is unsigned for the good reason that a negative sale
    // is not a thing.
    $coupon = Coupon::create([
        'code' => 'HUGE', 'discount_type' => Coupon::TYPE_FIXED, 'discount_value' => 999_999,
    ]);

    expect($coupon->discountFor(Money::ofMinor(9_000)))->toEqualPesewas(9_000);
});

it('discounts only the delivery for a free-shipping coupon', function () {
    $coupon = Coupon::create([
        'code' => 'FREESHIP', 'discount_type' => Coupon::TYPE_FREE_SHIPPING, 'discount_value' => 0,
    ]);

    expect($coupon->discountFor(Money::ofMinor(9_000), Money::ofMinor(2_500)))->toEqualPesewas(2_500);
});

it('says WHY a code was refused rather than just refusing it', function () {
    // "That code is not valid" with no explanation is how a customer abandons
    // a basket over a coupon that expired yesterday.
    $expired = Coupon::create([
        'code' => 'OLD', 'discount_value' => 1000,
        'starts_at' => now()->subYear(), 'expires_at' => now()->subDay(),
    ]);

    expect($expired->rejectionReason(Money::ofMinor(9_000)))->toContain('expired on');
});

it('enforces a minimum spend', function () {
    $coupon = Coupon::create([
        'code' => 'OVER100', 'discount_value' => 1000, 'minimum_spend' => 10_000,
    ]);

    expect($coupon->rejectionReason(Money::ofMinor(9_000)))->toContain('at least');
});

it('enforces a per-customer limit from the redemption rows', function () {
    $coupon = Coupon::create([
        'code' => 'ONCE', 'discount_value' => 1000, 'usage_limit_per_customer' => 1,
    ]);

    $coupon->redeem(Money::ofMinor(900), null, 'ama@example.com');

    expect($coupon->fresh()->rejectionReason(Money::ofMinor(9_000), 'ama@example.com'))
        ->toContain('already used')
        // A different customer is unaffected.
        ->and($coupon->fresh()->rejectionReason(Money::ofMinor(9_000), 'kofi@example.com'))
        ->toBeNull();
});

it('refuses a percentage over 100%', function () {
    Coupon::create(['code' => 'FREE', 'discount_value' => 10_001]);
})->throws(RuntimeException::class, 'cannot exceed 100%');

it('refuses the checkout outright when a coupon has gone stale', function () {
    // A customer who typed a code and sees no discount at the payment page
    // assumes the shop is broken.
    $coupon = Coupon::create([
        'code' => 'OLD', 'discount_value' => 1000,
        'starts_at' => now()->subYear(), 'expires_at' => now()->subDay(),
    ]);

    $cart = basketWith();
    $cart->update(['coupon_id' => $coupon->id]);

    $this->checkout->createOrder($cart->fresh()->load('items.variant.product'), checkoutDetails());
})->throws(RuntimeException::class, 'expired on');

it('records a redemption against the order', function () {
    $coupon = Coupon::create(['code' => 'FRIENDS10', 'discount_value' => 1000]);
    $cart = basketWith();
    $cart->update(['coupon_id' => $coupon->id]);

    $order = $this->checkout->createOrder($cart->fresh()->load('items.variant.product'), checkoutDetails());

    expect($order->discount)->toEqualPesewas(900)
        ->and($order->total)->toEqualPesewas(9_000 + 2_500 - 900)
        ->and($coupon->fresh()->times_used)->toBe(1)
        ->and($coupon->redemptions()->first()->order_id)->toBe($order->id);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Stock through the order lifecycle
// ═══════════════════════════════════════════════════════════════════════════

it('holds stock the moment the order is placed', function () {
    // Not on payment. Otherwise two customers buy the last mug while both are
    // on the payment page.
    $cart = basketWith(quantity: 2, stock: 3);
    $variant = $cart->items->first()->variant;

    $order = $this->checkout->createOrder($cart, checkoutDetails());

    expect($order->stock_held)->toBeTrue()
        ->and($variant->fresh()->stock_on_hand)->toBe(3)
        ->and($variant->fresh()->sellableQuantity())->toBe(1);
});

it('converts the hold into a sale when the payment settles', function () {
    $cart = basketWith(quantity: 2, stock: 3);
    $variant = $cart->items->first()->variant;

    ['order' => $order] = $this->checkout->start($cart, checkoutDetails());
    settleOrder($order);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($variant->fresh()->stock_on_hand)->toBe(1)
        ->and($variant->fresh()->stock_held)->toBe(0);
});

it('puts the stock back when the payment fails', function () {
    // A foundation with twelve mugs and eleven abandoned checkouts would
    // otherwise show as sold out.
    $cart = basketWith(quantity: 2, stock: 3);
    $variant = $cart->items->first()->variant;

    ['order' => $order, 'transaction' => $transaction] = $this->checkout->start($cart, checkoutDetails());
    $transaction->forceFill(['gateway_reference' => $transaction->gateway_reference.'-FAIL'])->save();

    $this->payments->verifyAndSettle($transaction->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($variant->fresh()->sellableQuantity())->toBe(3);
});

it('does not decrement the shelf twice on a replayed settlement', function () {
    $cart = basketWith(quantity: 2, stock: 5);
    $variant = $cart->items->first()->variant;

    ['order' => $order] = $this->checkout->start($cart, checkoutDetails());
    settleOrder($order);

    $order->fresh()->onPaymentSettled($order->fresh()->transaction);
    $order->fresh()->onPaymentSettled($order->fresh()->transaction);

    expect($variant->fresh()->stock_on_hand)->toBe(3);
});

it('keeps the stock held when a payment mismatches', function () {
    // Releasing would let somebody else buy goods this customer may well have
    // paid for; committing would ship against a payment nobody verified.
    $cart = basketWith(quantity: 2, stock: 3);
    $variant = $cart->items->first()->variant;

    ['order' => $order, 'transaction' => $transaction] = $this->checkout->start($cart, checkoutDetails());
    $transaction->settle(Money::ofMinor(1));
    $order->fresh()->onPaymentMismatch($transaction->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::NeedsReview)
        ->and($variant->fresh()->stock_held)->toBe(2)
        ->and($variant->fresh()->stock_on_hand)->toBe(3);
});

it('puts stock back on the shelf when a checkout is abandoned', function () {
    /*
     * The case that matters most for a small shop. Without it, twelve mugs and
     * eleven abandoned checkouts reads as sold out, and the goods sit reserved
     * for people who left.
     */
    $cart = basketWith(quantity: 2, stock: 3);
    $variant = $cart->items->first()->variant;

    ['order' => $order, 'transaction' => $transaction] = $this->checkout->start($cart, checkoutDetails());

    // The fake gateway leaves a -PENDING reference unresolved: the customer
    // opened the page and the gateway never heard from them again.
    $transaction->forceFill([
        'gateway_reference' => $transaction->gateway_reference.'-PENDING',
        'created_at' => now()->subHours(3),
    ])->save();

    app(ReconciliationService::class)->run();

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($variant->fresh()->stock_held)->toBe(0)
        ->and($variant->fresh()->sellableQuantity())->toBe(3);
});

it('lists stale unpaid orders still holding stock', function () {
    $cart = basketWith();
    $order = $this->checkout->createOrder($cart, checkoutDetails());
    $order->forceFill(['created_at' => now()->subHours(3)])->save();

    expect(Order::query()->stale()->pluck('id')->all())->toBe([$order->id]);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Order lifecycle
// ═══════════════════════════════════════════════════════════════════════════

it('records who changed the status and why', function () {
    // "When did this ship?" and "who cancelled it?" are the two questions a
    // customer service enquiry always asks.
    $order = Order::factory()->paid()->create();
    $staff = User::factory()->staff()->create();

    $order->transitionTo(OrderStatus::Shipped, $staff, 'Handed to the courier.');

    $history = OrderStatusHistory::latest('id')->first();

    expect($order->fresh()->shipped_at)->not->toBeNull()
        ->and($history->to_status)->toBe('shipped')
        ->and($history->changed_by)->toBe($staff->id)
        ->and($history->note)->toBe('Handed to the courier.');
});

it('marks a system change as automatic rather than attributing it to nobody', function () {
    $cart = basketWith();
    ['order' => $order] = $this->checkout->start($cart, checkoutDetails());
    settleOrder($order);

    expect(OrderStatusHistory::where('order_id', $order->id)->get()->last()->wasAutomatic())
        ->toBeTrue();
});

it('refuses to cancel a paid order outright', function () {
    // The money has to go back, and that is a separate record with its own
    // approval.
    Order::factory()->paid()->create()->cancel('Changed their mind.');
})->throws(RuntimeException::class, 'cannot be cancelled outright');

it('cancels an unpaid order and releases its stock', function () {
    $cart = basketWith(quantity: 2, stock: 3);
    $variant = $cart->items->first()->variant;
    $order = $this->checkout->createOrder($cart, checkoutDetails());

    $order->cancel('Customer changed their mind.');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($variant->fresh()->sellableQuantity())->toBe(3);
});

it('refuses to change the figures on a paid order', function () {
    $order = Order::factory()->paid()->create();

    $order->update(['total' => 1]);
})->throws(RuntimeException::class, 'append-only');

it('refuses to delete an order', function () {
    Order::factory()->create()->delete();
})->throws(RuntimeException::class, 'never deleted');

it('uses a different reference prefix from a donation', function () {
    // So nobody reading one aloud can confuse the two.
    expect(Order::factory()->create()->reference)->toStartWith('SCGHF-O-');
});

// ═══════════════════════════════════════════════════════════════════════════
//  Digital downloads
// ═══════════════════════════════════════════════════════════════════════════

it('gates a download on the order being paid', function () {
    $order = Order::factory()->create();
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_name' => 'Devotional', 'sku' => 'D1',
        'quantity' => 1, 'unit_price' => 2_000,
    ]);

    $token = DigitalDownloadToken::create(['order_id' => $order->id, 'order_item_id' => $item->id]);

    expect($token->isUsable())->toBeFalse()
        ->and($token->rejectionReason())->toContain('not been paid');
});

it('expires a download link and says so usefully', function () {
    // "This link expired on 3 September, contact us" is actionable; "access
    // denied" is not — and the person reading it has already paid.
    $order = Order::factory()->paid()->create();
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_name' => 'Devotional', 'sku' => 'D1',
        'quantity' => 1, 'unit_price' => 2_000,
    ]);

    $token = DigitalDownloadToken::create([
        'order_id' => $order->id, 'order_item_id' => $item->id,
        'expires_at' => now()->subDay(),
    ]);

    expect($token->isUsable())->toBeFalse()
        ->and($token->rejectionReason())->toContain('expired on');
});

it('counts downloads and stops at the limit', function () {
    $order = Order::factory()->paid()->create();
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_name' => 'Devotional', 'sku' => 'D1',
        'quantity' => 1, 'unit_price' => 2_000,
    ]);

    $token = DigitalDownloadToken::create([
        'order_id' => $order->id, 'order_item_id' => $item->id, 'max_downloads' => 2,
    ]);

    $token->recordDownload('41.66.1.20');
    $token->recordDownload();

    expect($token->fresh()->remainingDownloads())->toBe(0)
        ->and($token->fresh()->isUsable())->toBeFalse();
});

it('issues a token nobody could guess', function () {
    $order = Order::factory()->paid()->create();
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_name' => 'Devotional', 'sku' => 'D1',
        'quantity' => 1, 'unit_price' => 2_000,
    ]);

    $token = DigitalDownloadToken::create(['order_id' => $order->id, 'order_item_id' => $item->id]);

    expect(strlen($token->token))->toBe(64);
});

// ═══════════════════════════════════════════════════════════════════════════
//  Baskets
// ═══════════════════════════════════════════════════════════════════════════

it('holds no price, so a stale basket cannot quote a stale figure', function () {
    $cart = basketWith(price: 4_500);

    expect(Schema::hasColumn('cart_items', 'price_minor'))->toBeFalse()
        ->and($cart->subtotal())->toEqualPesewas(9_000);

    $cart->items->first()->variant->update(['price' => 5_000]);

    // Re-read live.
    expect($cart->fresh()->load('items.variant')->subtotal())->toEqualPesewas(10_000);
});

it('increments rather than duplicating when the same item is added twice', function () {
    $cart = basketWith(quantity: 1);
    $variant = $cart->items->first()->variant;

    $cart->add($variant, 2);

    expect($cart->fresh()->items)->toHaveCount(1)
        ->and($cart->fresh()->itemCount())->toBe(3);
});

it('merges a guest basket into a signed-in one', function () {
    $guest = basketWith(quantity: 1);
    $variant = $guest->items->first()->variant;

    $mine = Cart::create([]);
    $mine->add($variant, 2);

    $mine->fresh()->mergeFrom($guest->fresh());

    expect($mine->fresh()->itemCount())->toBe(3)
        ->and(Cart::count())->toBe(1);
});

it('expires so an abandoned basket does not keep personal data for ever', function () {
    $cart = Cart::create(['customer_email' => 'ama@example.com']);
    $cart->forceFill(['expires_at' => now()->subDay()])->save();

    expect(Cart::query()->expired()->count())->toBe(1);
});
