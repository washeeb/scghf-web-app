<?php

declare(strict_types=1);

use App\Communications\TemplateRenderer;
use App\Enums\DonationStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\ShippingZones\Pages\EditShippingZone;
use App\Models\Cart;
use App\Models\Cause;
use App\Models\Coupon;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Invoice;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\ScheduledMessage;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Policies\BasePolicy;
use App\Shop\CurrentCart;
use App\Support\PageMeta;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CauseSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ShippingZoneSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The shop
|--------------------------------------------------------------------------
|
| THE CATALOGUE, THE STOCK LEDGER, CARTS, COUPONS, SHIPPING ZONES, ORDERS AND
| INVOICES HAVE ALL EXISTED SINCE PHASE 3 — built and tested — with no page
| that showed a product, no admin screen that could create one, and
| `FEATURE_SHOP=true` in front of none of it.
|
| ⚠ THE BROWSER REDIRECT IS NOT PROOF OF PAYMENT. The same rule as the donation
| page: the order is marked paid by the signed webhook, and the confirmation
| page reports what the DATABASE says. A page that trusted `?status=success`
| could be made to show a paid order to anybody, and the foundation would post
| a parcel for money it never received.
|
| ⚠ THE CONFIRMATION EMAIL AND THE INVOICE EXISTED AND NOTHING SENT THEM. So
| did the donation receipt. Settlement now tells the customer.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(ShippingZoneSeeder::class);

    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** A published product with one variant and stock. */
function mug(int $stock = 10, int $price = 4_500, array $product = [], array $variant = []): ProductVariant
{
    $productModel = Product::factory()->create($product);
    $variantModel = ProductVariant::factory()->create(array_merge([
        'product_id' => $productModel->id,
        'price' => $price,
        'weight_grams' => 400,
    ], $variant));

    if ($stock > 0) {
        $variantModel->restock($stock);
    }

    return $variantModel->fresh()->load('product');
}

/** Accra, deliverable. */
function accraDelivery(): ShippingZone
{
    $zone = ShippingZone::where('slug', 'greater-accra')->firstOrFail();
    $zone->update(['is_active' => true]);
    ShippingRate::create(['shipping_zone_id' => $zone->id, 'name' => 'Standard', 'price' => 2_500, 'estimated_days' => '2–3 days']);
    ShippingZone::where('slug', 'collection')->update(['is_active' => true]);

    return $zone->fresh();
}

/** @param array<string, mixed> $overrides */
function checkoutPayload(array $overrides = []): array
{
    return array_merge([
        'customer_name' => 'Ama Boateng',
        'customer_email' => 'ama@example.test',
        'customer_phone' => '0241234567',
        'fulfilment' => 'deliver',
        'delivery_region' => 'Greater Accra',
        'delivery_city' => 'Accra',
        'delivery_area' => 'Madina',
        'delivery_address' => '12 Ring Road, opposite the filling station',
        'consent' => '1',
    ], $overrides);
}

/**
 * Add to the basket, and carry the basket cookie into every later request.
 *
 * The test client does not keep cookies between requests the way a browser
 * does, so the token the response set is read back and sent with everything
 * that follows — which is what a browser would do.
 */
function addToBasket(ProductVariant $variant, int $quantity = 1): TestResponse
{
    $response = test()->post(route('shop.cart.add'), ['variant' => $variant->ulid, 'quantity' => $quantity]);

    if ($cookie = $response->getCookie(CurrentCart::COOKIE)) {
        // `getCookie()` decrypts; `withCookie()` encrypts again on the way in.
        test()->withCookie(CurrentCart::COOKIE, $cookie->getValue());
    }

    return $response;
}

function shopManager(): User
{
    test()->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();

    return staffWithRole('Shop Manager');
}

// ── The flag ────────────────────────────────────────────────────────────────

it('turns the whole shop off with its feature flag', function () {
    config(['features.shop' => false]);

    $this->get(route('shop.index'))->assertNotFound();
    $this->get(route('shop.cart'))->assertNotFound();
});

// ── Browsing ────────────────────────────────────────────────────────────────

it('lists live products and only live products', function () {
    $live = mug();
    Product::factory()->draft()->create(['name' => 'Draft Tote']);
    Product::factory()->regulated()->create(['name' => 'Vitamin Water', 'is_published' => true]);

    $this->get(route('shop.index'))
        ->assertOk()
        ->assertSee($live->product->name)
        ->assertDontSee('Draft Tote')
        ->assertDontSee('Vitamin Water');
});

it('says what a purchase funds', function () {
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);
    $cause = Cause::first();

    $variant = mug(product: ['cause_id' => $cause->id]);

    $this->get(route('shop.show', $variant->product))
        ->assertOk()
        ->assertSee($cause->title);
});

it('lists a parent category with its children\'s products', function () {
    $parent = ProductCategory::factory()->create(['name' => 'Clothing']);
    $child = ProductCategory::factory()->create(['name' => 'T-shirts', 'parent_id' => $parent->id]);
    $variant = mug(product: ['product_category_id' => $child->id, 'name' => 'Legacy Tee']);

    $this->get(route('shop.category', $parent))->assertOk()->assertSee('Legacy Tee');
    $this->get(route('shop.category', $child))->assertOk()->assertSee('Legacy Tee');
});

it('marks a sold-out product without hiding it', function () {
    $variant = mug(stock: 0);

    $this->get(route('shop.index'))->assertOk()->assertSee($variant->product->name)->assertSee('Sold out');
    $this->get(route('shop.show', $variant->product))->assertOk()->assertDontSee('Add to basket');
});

it('does not double the title suffix on a code-backed page', function () {
    $suffix = (string) setting('seo.title_suffix');

    expect(PageMeta::site('Shop'.$suffix)->title)->toBe('Shop'.$suffix)
        ->and(PageMeta::site('Shop')->title)->toBe('Shop'.$suffix);
});

// ── The basket ──────────────────────────────────────────────────────────────

it('puts something in the basket and remembers it in a cookie', function () {
    $variant = mug();

    $response = $this->post(route('shop.cart.add'), ['variant' => $variant->ulid, 'quantity' => 2])
        ->assertRedirect(route('shop.cart'))
        ->assertCookie(CurrentCart::COOKIE);

    $cart = Cart::first();

    expect($cart->items()->count())->toBe(1)
        ->and($cart->items()->first()->quantity)->toBe(2)
        ->and($cart->session_token)->toBe($response->getCookie(CurrentCart::COOKIE)->getValue());
});

it('increments rather than duplicating a line', function () {
    $variant = mug();

    addToBasket($variant);
    addToBasket($variant, 2);

    expect(Cart::first()->items()->count())->toBe(1)
        ->and(Cart::first()->items()->first()->quantity)->toBe(3);
});

it('refuses to add more than there is', function () {
    $variant = mug(stock: 2);

    $this->post(route('shop.cart.add'), ['variant' => $variant->ulid, 'quantity' => 3])
        ->assertSessionHasErrors('quantity');

    expect(Cart::count())->toBe(1)->and(Cart::first()->items()->count())->toBe(0);
});

it('refuses a variant of a product that is not on sale', function () {
    $draft = Product::factory()->draft()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $draft->id]);

    $this->post(route('shop.cart.add'), ['variant' => $variant->ulid])
        ->assertSessionHasErrors('variant');
});

it('shows the basket with live prices, and lets a line be changed and removed', function () {
    $variant = mug(price: 4_500);
    addToBasket($variant, 2);

    $this->get(route('shop.cart'))
        ->assertOk()
        ->assertSee($variant->product->name)
        ->assertSee('GH₵ 90.00');

    $this->patch(route('shop.cart.update', $variant), ['quantity' => 1])->assertRedirect(route('shop.cart'));
    expect(Cart::first()->items()->first()->quantity)->toBe(1);

    $this->delete(route('shop.cart.remove', $variant))->assertRedirect(route('shop.cart'));
    expect(Cart::first()->items()->count())->toBe(0);
});

it('marks a basket line that can no longer be bought, and blocks checkout', function () {
    $variant = mug(stock: 5);
    addToBasket($variant, 5);

    // Somebody else bought four.
    $variant->fresh()->adjust(-4, 'Sold over the counter.');

    $this->get(route('shop.cart'))
        ->assertOk()
        ->assertSee('Only 1 left')
        ->assertDontSee('Go to checkout');

    $this->get(route('shop.checkout'))->assertRedirect(route('shop.cart'));
});

it('applies a discount code, and refuses one with the reason', function () {
    $variant = mug(price: 10_000);
    addToBasket($variant);

    Coupon::create(['code' => 'friends10', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 1000]);
    Coupon::create(['code' => 'OLD', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 1000, 'expires_at' => now()->subDay()]);

    $this->post(route('shop.cart.coupon'), ['code' => 'FRIENDS10'])->assertRedirect(route('shop.cart'));
    $this->get(route('shop.cart'))->assertSee('FRIENDS10')->assertSee('GH₵ 10.00');

    $this->delete(route('shop.cart.coupon.remove'));
    $this->post(route('shop.cart.coupon'), ['code' => 'OLD'])->assertSessionHasErrors('code');
    $this->post(route('shop.cart.coupon'), ['code' => 'NOPE'])->assertSessionHasErrors('code');
});

it('shows the basket count in the header once there is something in it', function () {
    $this->get('/')->assertDontSee('aria-label="Basket', escape: false);

    $variant = mug();
    addToBasket($variant, 3);

    $this->get('/')->assertSee('Basket, 3 items', escape: false);
});

it('keeps a guest basket when the guest signs in', function () {
    $variant = mug();
    addToBasket($variant, 2);

    $user = User::factory()->create(['password' => bcrypt('correct-horse-battery')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery']);

    $cart = Cart::where('user_id', $user->id)->first();

    expect($cart)->not->toBeNull()
        ->and($cart->items()->first()->quantity)->toBe(2)
        ->and(Cart::count())->toBe(1);
});

// ── Checkout ────────────────────────────────────────────────────────────────

it('sends an empty basket back rather than showing a checkout', function () {
    $this->get(route('shop.checkout'))->assertRedirect(route('shop.cart'));
});

it('shows the delivery charge for this basket, region by region', function () {
    accraDelivery();
    $variant = mug();
    addToBasket($variant);

    $this->get(route('shop.checkout'))
        ->assertOk()
        ->assertSee('Greater Accra')
        ->assertSee('GH₵ 25.00')
        ->assertSee('I will collect it');
});

it('refuses a region the shop does not deliver to', function () {
    accraDelivery();
    $variant = mug();
    addToBasket($variant);

    $this->post(route('shop.checkout.store'), checkoutPayload(['delivery_region' => 'Savannah']))
        ->assertSessionHasErrors('delivery_region');

    expect(Order::count())->toBe(0);
});

it('needs a phone number for delivery but not for collection', function () {
    accraDelivery();
    $variant = mug();
    addToBasket($variant);

    $this->post(route('shop.checkout.store'), checkoutPayload(['customer_phone' => '']))
        ->assertSessionHasErrors('customer_phone');

    $this->post(route('shop.checkout.store'), checkoutPayload(['customer_phone' => '', 'fulfilment' => 'collect']))
        ->assertRedirect();

    expect(Order::first()->is_pickup)->toBeTrue()
        ->and(Order::first()->shipping->isZero())->toBeTrue();
});

it('writes the order, holds the stock, empties the basket and sends the customer to pay', function () {
    accraDelivery();
    $variant = mug(stock: 10, price: 4_500);
    addToBasket($variant, 2);

    $response = $this->post(route('shop.checkout.store'), checkoutPayload());

    $order = Order::first();
    $transaction = PaymentTransaction::first();

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->subtotal->toMinor())->toBe(9_000)
        ->and($order->shipping->toMinor())->toBe(2_500)
        ->and($order->total->toMinor())->toBe(11_500)
        ->and($order->stock_held)->toBeTrue()
        ->and($variant->fresh()->stock_held)->toBe(2)
        ->and($variant->fresh()->sellableQuantity())->toBe(8)
        ->and(Cart::first()->items()->count())->toBe(0)
        ->and($transaction->payable_id)->toBe($order->id);

    $response->assertRedirect($transaction->authorization_url);
});

it('does not believe the query string about the outcome', function () {
    accraDelivery();
    $variant = mug();
    addToBasket($variant);
    $this->post(route('shop.checkout.store'), checkoutPayload());

    $order = Order::first();

    $this->get(route('shop.order', $order).'?status=success')
        ->assertOk()
        ->assertSee('confirming your payment')
        ->assertDontSee('Thank you for your order');
});

it('walks the whole journey: basket, checkout, sandbox, webhook, confirmation, invoice, email', function () {
    accraDelivery();
    $variant = mug(stock: 10);
    addToBasket($variant, 2);
    $this->post(route('shop.checkout.store'), checkoutPayload());

    $transaction = PaymentTransaction::first();

    $this->post(route('payments.fake.pay', $transaction->gateway_reference), ['outcome' => 'success'])
        ->assertRedirect(route('shop.checkout.callback', ['reference' => $transaction->gateway_reference]));

    $order = Order::first()->fresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($variant->fresh()->stock_on_hand)->toBe(8)
        ->and($variant->fresh()->stock_held)->toBe(0)
        ->and(Invoice::where('order_id', $order->id)->exists())->toBeTrue();

    // The callback looks the reference UP and lands on the order, by a signed link.
    $landing = $this->get(route('shop.checkout.callback', ['reference' => $transaction->gateway_reference]))
        ->assertRedirect()
        ->headers->get('Location');

    expect($landing)->toStartWith(route('shop.order', $order))->toContain('signature=');

    $this->get($landing)
        ->assertOk()
        ->assertSee('Thank you for your order')
        ->assertSee($order->reference);

    // The confirmation, with the lines as an HTML list that survived the outbox.
    $message = ScheduledMessage::where('template_key', 'order.confirmation')->first();

    expect($message)->not->toBeNull()
        ->and($message->to_address)->toBe('ama@example.test')
        ->and($message->payload['order_items'])->toHaveKey('__html')
        ->and($message->payload['order_items']['__html'])->toContain('<li>2 ×');

    // Settled twice — a replayed webhook — is one invoice and one email.
    app(PaymentManager::class)->verifyAndSettle($transaction->fresh());

    expect(Invoice::count())->toBe(1)
        ->and(ScheduledMessage::where('template_key', 'order.confirmation')->count())->toBe(1);
});

it('puts the stock back and says so when the payment fails', function () {
    accraDelivery();
    $variant = mug(stock: 3);
    addToBasket($variant, 3);
    $this->post(route('shop.checkout.store'), checkoutPayload());

    $transaction = PaymentTransaction::first();
    $this->post(route('payments.fake.pay', $transaction->gateway_reference), ['outcome' => 'fail']);

    $order = Order::first()->fresh();

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($variant->fresh()->sellableQuantity())->toBe(3);

    $this->get(route('shop.order', $order))->assertOk()->assertSee('not completed');
});

it('releases stock held by an abandoned checkout within the hour, not the day', function () {
    accraDelivery();
    $variant = mug(stock: 3);
    addToBasket($variant, 3);
    $this->post(route('shop.checkout.store'), checkoutPayload());

    expect($variant->fresh()->sellableQuantity())->toBe(0);

    // The fake gateway answers "paid" to any verify unless the reference says
    // otherwise — the sweep asks one last time before writing an order off,
    // and this is the customer who never approved the prompt.
    PaymentTransaction::first()->update(['gateway_reference' => PaymentTransaction::first()->gateway_reference.'-PENDING']);

    $this->travel(2)->hours();

    $this->artisan('scghf:sweep-shop --execute')->assertSuccessful();

    expect($variant->fresh()->sellableQuantity())->toBe(3)
        ->and(Order::first()->status)->toBe(OrderStatus::Cancelled);
});

it('deletes expired baskets in the same sweep', function () {
    $stale = Cart::create(['expires_at' => now()->subDay()]);
    $fresh = Cart::create([]);

    $this->artisan('scghf:sweep-shop --execute')->assertSuccessful();

    expect(Cart::find($stale->id))->toBeNull()
        ->and(Cart::find($fresh->id))->not->toBeNull();
});

// ── The donation receipt that nothing sent ──────────────────────────────────

it('issues a receipt and queues the acknowledgement once a gift is settled', function () {
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);

    $this->post(route('donate.store'), [
        'amount' => '50.00',
        'donor_name' => 'Ama Mensah',
        'donor_email' => 'ama@example.test',
        'donor_phone' => '0241234567',
        'consent' => '1',
    ]);

    $transaction = PaymentTransaction::first();
    $this->post(route('payments.fake.pay', $transaction->gateway_reference), ['outcome' => 'success']);

    $donation = Donation::first();

    expect($donation->status)->toBe(DonationStatus::Completed)
        ->and(DonationReceipt::where('donation_id', $donation->id)->exists())->toBeTrue();

    $email = ScheduledMessage::where('template_key', 'donation.receipt')->first();
    $sms = ScheduledMessage::where('template_key', 'donation.received')->first();

    expect($email)->not->toBeNull()
        ->and($email->payload['acknowledgement'])->toHaveKey('__html')
        ->and($email->payload['receipt_number'])->toBe(DonationReceipt::first()->receipt_number)
        ->and($sms)->not->toBeNull()
        ->and($sms->payload['amount'])->toBe('50.00');
});

it('prints an HTML variable as HTML in the HTML body and as text in the text body', function () {
    $renderer = app(TemplateRenderer::class);
    $html = new HtmlString('<p>Borehole <strong>capped</strong>.</p>');

    expect($renderer->render('X {{body}} Y', ['body' => $html], escape: true))->toBe('X <p>Borehole <strong>capped</strong>.</p> Y')
        ->and($renderer->render('X {{body}} Y', ['body' => $html]))->toBe('X Borehole capped. Y')
        // A plain string is still escaped — that is where stored XSS would land.
        ->and($renderer->render('{{name}}', ['name' => '<b>Ama</b>'], escape: true))->toBe('&lt;b&gt;Ama&lt;/b&gt;');
});

// ── The admin ───────────────────────────────────────────────────────────────

it('lets a shop manager create a product with a variant priced in cedis', function () {
    $this->actingAs(shopManager());

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Legacy Tote Bag',
            'slug' => 'legacy-tote-bag',
            'product_type' => Product::TYPE_PHYSICAL,
            'variants' => [
                ['sku' => 'TOTE-001', 'price' => '45.50', 'tracks_stock' => true, 'is_active' => true],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('slug', 'legacy-tote-bag')->firstOrFail();

    expect($product->variants)->toHaveCount(1)
        ->and($product->variants->first()->price_minor)->toBe(4_550)
        ->and($product->variants->first()->price->format())->toBe('GH₵ 45.50');
});

it('records stock through the ledger, never by typing over it', function () {
    $this->actingAs(shopManager());
    $variant = mug(stock: 0);

    Livewire::test(EditProduct::class, ['record' => $variant->product->slug])
        ->callAction('adjustStock', data: ['variant' => $variant->id, 'kind' => 'restock', 'quantity' => 12, 'note' => 'Delivery from the printer'])
        ->assertHasNoActionErrors();

    expect($variant->fresh()->stock_on_hand)->toBe(12)
        ->and($variant->movements()->count())->toBe(1);

    // A correction with no note is refused by the model, and the page says so.
    Livewire::test(EditProduct::class, ['record' => $variant->product->slug])
        ->callAction('adjustStock', data: ['variant' => $variant->id, 'kind' => 'adjust', 'quantity' => -2, 'note' => ''])
        ->assertHasActionErrors(['note']);
});

it('keeps a flagged product off sale until a review is recorded on the page', function () {
    $this->actingAs(shopManager());
    $product = Product::factory()->regulated()->create();

    expect($product->needsRegulatoryReview())->toBeTrue();

    Livewire::test(EditProduct::class, ['record' => $product->slug])
        ->callAction('regulatoryReview', data: ['reference' => 'FDA/2026/0042'])
        ->assertHasNoActionErrors();

    $product->refresh();

    expect($product->needsRegulatoryReview())->toBeFalse()
        ->and($product->regulatory_reference)->toBe('FDA/2026/0042');

    Livewire::test(ListProducts::class)->assertOk();
});

it('lets a shop manager price a delivery zone', function () {
    $this->actingAs(shopManager());
    $zone = ShippingZone::where('slug', 'greater-accra')->firstOrFail();

    Livewire::test(EditShippingZone::class, ['record' => $zone->getRouteKey()])
        ->fillForm([
            'is_active' => true,
            'rates' => [
                ['name' => 'Standard', 'price' => '25.00', 'estimated_days' => '2–3 days', 'is_active' => true],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($zone->fresh()->rates()->count())->toBe(1)
        ->and($zone->fresh()->rates()->first()->price_minor)->toBe(2_500)
        ->and(ShippingZone::forRegion('Greater Accra')?->id)->toBe($zone->id);
});

it('lets a shop manager mark an order dispatched, which tells the customer', function () {
    $this->actingAs(shopManager());
    $order = Order::factory()->paid()->create(['customer_phone' => '0241234567', 'is_pickup' => false]);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertOk()
        ->callAction('shipped', data: ['courier' => 'DHL Ghana', 'tracking' => 'GH123'])
        ->assertHasNoActionErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Shipped)
        ->and(ScheduledMessage::where('template_key', 'order.shipped')->where('channel', 'email')->exists())->toBeTrue()
        ->and(ScheduledMessage::where('template_key', 'order.shipped')->where('channel', 'sms')->exists())->toBeTrue();
});

it('will not let an order that has been paid be cancelled from the page', function () {
    $this->actingAs(shopManager());
    $order = Order::factory()->paid()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertActionHidden('cancel');
});

// ── The navigation that pointed at routes nobody built ──────────────────────

it('renders the Donate pill and the Shop link from the seeded menu, not a fallback', function () {
    $this->seed(PageSeeder::class);
    $this->seed(MenuSeeder::class);

    expect(Route::has('donate'))->toBeTrue()
        ->and(Menu::renderable('header')->firstWhere('is_highlighted', true))->not->toBeNull();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('href="'.route('shop.index').'"')
        ->toContain('href="'.route('impact').'"')
        ->toContain('href="'.route('focus-areas.index').'"')
        // The pill is the seeded item, so it appears exactly once in the header.
        ->and(substr_count(explode('<main', $html, 2)[0], 'href="'.route('donate').'"'))->toBe(1);
});

it('repoints a menu seeded with the old route names', function () {
    $this->seed(PageSeeder::class);
    $this->seed(MenuSeeder::class);

    MenuItem::where('route_name', 'donate')->update(['route_name' => 'donate.index']);

    $this->seed(MenuSeeder::class);

    expect(MenuItem::where('route_name', 'donate.index')->exists())->toBeFalse()
        ->and(MenuItem::where('route_name', 'donate')->exists())->toBeTrue();
});
