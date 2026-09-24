<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Policies\BasePolicy;
use App\Shop\CourierService;
use App\Support\Settings;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Courier deliveries
|--------------------------------------------------------------------------
|
| The office hands an order to a courier; the courier confirms each step
| from their phone; the order and the customer follow. And the lines a
| courier cannot cross: somebody else's parcel, a step out of order, the
| admin panel.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
    BasePolicy::forgetKnownPermissions();
    app(Settings::class)->set('contact.email_shop', 'shop@example.test');
    app(Settings::class)->flush();
    Storage::fake('local');
});

/** A courier: a public account (it signs in at /login) holding the Courier role. */
function courier(): User
{
    $user = User::factory()->donor()->create();
    $user->assignRole('Courier');

    return $user->fresh();
}

function deliveryOrder(): Order
{
    $order = Order::factory()->paid()->create([
        'is_pickup' => false,
        'customer_phone' => '0241234567',
        'delivery_name' => 'Ama Mensah',
        'delivery_phone' => '0209876543',
        'delivery_address' => 'House 12, Atia Road',
        'delivery_area' => 'Lamptey Mills',
        'delivery_city' => 'Kasoa',
    ]);

    $product = Product::factory()->create();
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Mug', 'sku' => 'X1', 'quantity' => 2, 'unit_price' => 1_000, 'line_total' => 2_000]);

    return $order->fresh();
}

// ── Assigning ────────────────────────────────────────────────────────────────

it('lets a shop manager assign a courier from the order page, which emails the courier', function () {
    $manager = staffWithRole('Shop Manager');
    $rider = courier();
    $order = deliveryOrder();

    Livewire::actingAs($manager)
        ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('assignCourier', data: ['courier_id' => $rider->id, 'notes' => 'Call first.'])
        ->assertHasNoActionErrors();

    $delivery = $order->fresh()->delivery;
    expect($delivery)->not->toBeNull()
        ->and($delivery->courier_id)->toBe($rider->id)
        ->and($delivery->status)->toBe(Delivery::STATUS_ASSIGNED)
        ->and($delivery->office_notes)->toBe('Call first.')
        ->and(ScheduledMessage::query()->where('template_key', 'delivery.assigned')->where('to_address', $rider->email)->exists())->toBeTrue()
        ->and($order->fresh()->history()->where('note', 'like', 'Assigned to%')->exists())->toBeTrue();
});

it('refuses to assign somebody who is not a courier, a collection order, or an order not paid', function () {
    $service = app(CourierService::class);
    $order = deliveryOrder();

    expect(fn () => $service->assign($order, staffWithRole('Content Editor')))->toThrow(RuntimeException::class, 'not a courier');
    expect(fn () => $service->assign(Order::factory()->paid()->pickup()->create(), courier()))->toThrow(RuntimeException::class, 'not for delivery');
    expect(fn () => $service->assign(Order::factory()->create(['is_pickup' => false]), courier()))->toThrow(RuntimeException::class);
});

// ── The courier's portal ─────────────────────────────────────────────────────

it('shows a courier only their own deliveries and lands them there on sign-in', function () {
    $rider = courier();
    $other = courier();
    $mine = app(CourierService::class)->assign(deliveryOrder(), $rider);
    $theirs = app(CourierService::class)->assign(deliveryOrder(), $other);

    $this->actingAs($rider)->get(route('courier.index'))->assertOk()
        ->assertSee($mine->order->reference)->assertDontSee($theirs->order->reference);

    $this->actingAs($rider)->get(route('courier.show', $mine))->assertOk()->assertSee('Ama Mensah');
    $this->actingAs($rider)->get(route('courier.show', $theirs))->assertNotFound();
    $this->actingAs($rider)->post(route('courier.picked-up', $theirs))->assertNotFound();

    // Not a courier: no portal.
    $this->actingAs(User::factory()->create())->get(route('courier.index'))->assertForbidden();

    // And a courier cannot open the admin, but can sign in at the site's own form — and lands on their deliveries.
    expect($rider->can('admin.access'))->toBeFalse()->and($rider->canAccessPanel())->toBeFalse();
    $this->post('/logout');
    $this->post('/login', ['email' => $rider->email, 'password' => 'password'])->assertRedirect(route('courier.index'));
});

it('walks a delivery from picked up to delivered, moving the order and telling the customer', function () {
    $rider = courier();
    $order = deliveryOrder();
    $delivery = app(CourierService::class)->assign($order, $rider);

    $this->actingAs($rider)->post(route('courier.picked-up', $delivery))->assertRedirect(route('courier.show', $delivery));
    expect($order->fresh()->status)->toBe(OrderStatus::Shipped)
        ->and($delivery->fresh()->status)->toBe(Delivery::STATUS_PICKED_UP)
        ->and(ScheduledMessage::query()->where('template_key', 'order.shipped')->exists())->toBeTrue();

    $this->actingAs($rider)->post(route('courier.out-for-delivery', $delivery))->assertRedirect();
    expect($order->fresh()->status)->toBe(OrderStatus::OutForDelivery);

    $this->actingAs($rider)->post(route('courier.delivered', $delivery), [
        'recipient_name' => 'Kofi Mensah',
        'note' => 'Left with her brother at the gate.',
        'photo' => UploadedFile::fake()->image('door.jpg', 800, 600),
        'lat' => '5.5340000',
        'lng' => '-0.4200000',
    ])->assertRedirect(route('courier.index'));

    $delivery->refresh();
    expect($delivery->status)->toBe(Delivery::STATUS_DELIVERED)
        ->and($delivery->recipient_name)->toBe('Kofi Mensah')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->hasProofPhoto())->toBeTrue()
        ->and($delivery->proofMapUrl())->toContain('5.5340000,-0.4200000')
        ->and($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and($order->fresh()->delivered_at)->not->toBeNull();

    Storage::disk('local')->assertExists($delivery->proof_photo_path);

    // The photograph: the courier and the office may see it; a donor may not.
    $this->actingAs($rider)->get(route('deliveries.proof', $delivery))->assertOk();
    $this->actingAs(staffWithRole('Shop Manager'))->get(route('deliveries.proof', $delivery))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('deliveries.proof', $delivery))->assertForbidden();

    // Nothing more to do: the buttons are gone.
    $this->actingAs($rider)->get(route('courier.show', $delivery))->assertOk()->assertDontSee(route('courier.picked-up', $delivery));
});

it('records a failed attempt with its reason, keeps the order where it was, and tells the office', function () {
    $rider = courier();
    $order = deliveryOrder();
    $delivery = app(CourierService::class)->assign($order, $rider);
    app(CourierService::class)->outForDelivery($delivery, $rider);

    $this->actingAs($rider)->post(route('courier.failed', $delivery), ['reason' => 'Nobody at the address', 'detail' => 'Gate locked, phone off.'])->assertRedirect();

    $delivery->refresh();
    expect($delivery->status)->toBe(Delivery::STATUS_FAILED)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->failure_reason)->toContain('Gate locked')
        ->and($order->fresh()->status)->toBe(OrderStatus::OutForDelivery)
        ->and(ScheduledMessage::query()->where('template_key', 'delivery.failed')->where('to_address', 'shop@example.test')->exists())->toBeTrue();

    // The second attempt can go straight to delivered.
    $this->actingAs($rider)->post(route('courier.delivered', $delivery), ['recipient_name' => 'Ama Mensah'])->assertRedirect(route('courier.index'));
    expect($delivery->fresh()->attempts)->toBe(2)->and($order->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('requires a photograph when the setting says so, and every portal word comes from the settings', function () {
    app(Settings::class)->set('courier.require_photo', true);
    app(Settings::class)->set('courier.list_title', 'Parcels for you');
    app(Settings::class)->set('courier.picked_up_label', 'Got it');
    app(Settings::class)->set('courier.failure_reasons', ['Dog in the yard']);
    app(Settings::class)->flush();

    $rider = courier();
    $delivery = app(CourierService::class)->assign(deliveryOrder(), $rider);

    $this->actingAs($rider)->get(route('courier.index'))->assertOk()->assertSee('Parcels for you');
    $this->actingAs($rider)->get(route('courier.show', $delivery))->assertOk()->assertSee('Got it')->assertSee('Dog in the yard');

    $this->actingAs($rider)->from(route('courier.show', $delivery))
        ->post(route('courier.delivered', $delivery), ['recipient_name' => 'Ama'])
        ->assertSessionHasErrors('photo');

    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_ASSIGNED);
});

it('reassigns from the office and keeps the attempts; a cancelled delivery leaves the courier\'s list', function () {
    $service = app(CourierService::class);
    $first = courier();
    $second = courier();
    $order = deliveryOrder();
    $delivery = $service->assign($order, $first);
    $service->failed($delivery, $first, 'Nobody home');

    $service->assign($order, $second, staffWithRole('Shop Manager'));
    $delivery->refresh();
    expect($delivery->courier_id)->toBe($second->id)
        ->and($delivery->status)->toBe(Delivery::STATUS_ASSIGNED)
        ->and($delivery->attempts)->toBe(1)
        ->and(Delivery::query()->where('order_id', $order->id)->count())->toBe(1);

    $this->actingAs($first)->get(route('courier.index'))->assertDontSee($order->reference);

    $service->cancel($delivery, staffWithRole('Shop Manager'), 'Customer collecting instead.');
    expect($delivery->fresh()->status)->toBe(Delivery::STATUS_CANCELLED);
    $this->actingAs($second)->get(route('courier.index'))->assertOk()->assertSee('Nothing to deliver');
});
