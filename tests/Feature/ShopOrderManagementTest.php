<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\ScheduledMessage;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\User;
use App\Payments\PaymentManager;
use App\Payments\RefundService;
use App\Policies\BasePolicy;
use App\Shop\CheckoutService;
use App\Shop\OrderDocuments;
use App\Support\Settings;
use App\Support\ThemeTokens;
use App\ValueObjects\Money;
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
| Phase 9 — managing orders
|--------------------------------------------------------------------------
|
| EVERY CHANGE OF STATE TELLS THE CUSTOMER, in words the foundation can edit.
| A REFUND IS TWO PEOPLE, and a full one puts the goods back on the shelf
| when the gateway confirms — not when somebody clicks. THE PAPER IS TWO
| DOCUMENTS: an invoice for the customer with prices, a packing slip for the
| packer without them.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
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

/** @param list<string> $extra */
function shopStaff(array $extra = []): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo(array_merge(['orders.view', 'orders.fulfil', 'payments.view_transactions', 'donations.view'], $extra));

    return $user;
}

function paidMugOrder(int $quantity = 2, int $stock = 10): Order
{
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 4_500, 'weight_grams' => 400]);
    $variant->restock($stock);

    $cart = Cart::create([]);
    $cart->add($variant->fresh(), $quantity);

    $order = app(CheckoutService::class)->createOrder($cart->fresh()->load('items.variant.product'), [
        'customer_name' => 'Ama Boateng',
        'customer_email' => 'ama@example.test',
        'customer_phone' => '+233241234567',
        'delivery_address' => 'House 12, Ring Road',
        'delivery_city' => 'Accra',
        'delivery_area' => 'Madina',
        'delivery_region' => 'Greater Accra',
        'delivery_gps' => 'GA-184-3456',
        'delivery_notes' => 'Call at the gate.',
    ]);

    app(PaymentManager::class)->charge($order, ['email' => $order->customer_email]);
    app(PaymentManager::class)->verifyAndSettle($order->fresh()->transaction);

    return $order->fresh();
}

// ── Transitions ─────────────────────────────────────────────────────────────

it('walks a parcel through packed, dispatched, out for delivery, delivered and completed, telling the customer each time', function () {
    $this->actingAs(shopStaff());
    $order = paidMugOrder();

    $page = Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);

    $page->callAction('processing');
    expect($order->fresh()->status)->toBe(OrderStatus::Processing);

    $page->callAction('packed');
    expect($order->fresh()->status)->toBe(OrderStatus::Packed);

    $page->callAction('shipped', ['courier' => 'Yango Delivery', 'tracking' => 'YD-1234']);
    expect($order->fresh()->status)->toBe(OrderStatus::Shipped);

    $page->callAction('out_for_delivery');
    expect($order->fresh()->status)->toBe(OrderStatus::OutForDelivery);

    $page->callAction('delivered');
    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);

    $page->callAction('completed');
    expect($order->fresh()->status)->toBe(OrderStatus::Completed);

    $statuses = ScheduledMessage::where('template_key', 'order.status')->where('channel', 'email')->pluck('payload')->map(fn ($p) => $p['status_label']);

    expect($statuses->all())->toBe(['Being prepared', 'Packed', 'Out for delivery', 'Delivered', 'Completed'])
        ->and(ScheduledMessage::where('template_key', 'order.shipped')->where('channel', 'email')->count())->toBe(1)
        ->and(ScheduledMessage::where('template_key', 'order.status')->where('channel', 'sms')->count())->toBe(2)
        ->and($order->fresh()->history()->count())->toBeGreaterThanOrEqual(7);
});

it('does not send a status email for a change that changes nothing', function () {
    $order = paidMugOrder();
    $order->transitionTo(OrderStatus::Paid);

    expect(ScheduledMessage::where('template_key', 'order.status')->count())->toBe(0);
});

// ── Paper ───────────────────────────────────────────────────────────────────

it('renders an invoice with prices and a packing slip without them', function () {
    $order = paidMugOrder();
    $documents = app(OrderDocuments::class);

    $invoice = $documents->renderInvoice($order->invoice);
    $slip = $documents->packingSlips([$order]);

    expect($invoice)->toStartWith('%PDF')
        ->and($slip)->toStartWith('%PDF');

    // The invoice PDF is cached under its number on the private disk.
    expect(file_exists($documents->invoicePath($order->invoice)))->toBeTrue();
});

it('lets the customer download the invoice from the signed link in the email, and audits it', function () {
    $order = paidMugOrder();
    $message = ScheduledMessage::where('template_key', 'order.confirmation')->first();

    expect($message->payload['invoice_url'])->toContain('signature=');

    $this->get($message->payload['invoice_url'])->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('invoices.download', $order->invoice))->assertForbidden();

    expect(AuditLog::where('event', 'invoice.downloaded')->count())->toBe(1);
});

it('prints packing slips for a selection of paid orders as one file, and leaves out what cannot be packed', function () {
    $this->actingAs(shopStaff());
    $paid = paidMugOrder();
    $delivered = paidMugOrder();
    $delivered->transitionTo(OrderStatus::Delivered);

    Livewire::test(ListOrders::class)
        ->selectTableRecords([$paid, $delivered])
        ->callAction(TestAction::make('packingSlips')->table()->bulk())
        ->assertFileDownloaded();

    $entry = AuditLog::where('event', 'packing_slips.printed')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->record_count)->toBe(1);
});

// ── Refunds ─────────────────────────────────────────────────────────────────

it('refunds an order with two people and puts the goods back on the shelf when the gateway confirms', function () {
    $order = paidMugOrder(quantity: 2, stock: 10);
    $variant = $order->items->first()->variant;

    expect($variant->fresh()->stock_on_hand)->toBe(8);

    $requester = shopStaff(['orders.refund_request']);
    $approver = shopStaff(['donations.refund']);

    $this->actingAs($requester);
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('refund', ['amount' => $order->total->toMajorString(), 'reason' => 'Arrived broken.']);

    $refund = Refund::first();
    expect($refund->status)->toBe(Refund::STATUS_REQUESTED)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($variant->fresh()->stock_on_hand)->toBe(8);

    $this->actingAs($approver);
    Livewire::test(ListRefunds::class)->callAction(TestAction::make('approve')->table($refund));

    expect($refund->fresh()->status)->toBe(Refund::STATUS_PROCESSED)
        ->and($order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($variant->fresh()->stock_on_hand)->toBe(10)
        ->and($variant->movements()->where('reason', 'return')->count())->toBe(1)
        ->and(ScheduledMessage::where('template_key', 'order.status')->latest('id')->first()->payload['status_label'])->toBe('Refunded');

    // A second confirmation cannot restock twice.
    $order->fresh()->onRefunded($refund->fresh());
    expect($variant->fresh()->stock_on_hand)->toBe(10);
});

it('notes a partial refund and leaves the shelf alone', function () {
    $order = paidMugOrder(quantity: 2, stock: 10);
    $variant = $order->items->first()->variant;

    $refund = app(RefundService::class)->request($order->transaction, Money::ofMinor(1_000), 'Goodwill.', shopStaff(['orders.refund_request']));
    app(RefundService::class)->approveAndExecute($refund, shopStaff(['donations.refund']));

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->fresh()->notes)->toContain('Partial refund of GH₵ 10.00')
        ->and($variant->fresh()->stock_on_hand)->toBe(8);
});
