<?php

declare(strict_types=1);

namespace App\Shop;

use App\Communications\MessageDispatcher;
use App\Enums\OrderStatus;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Deliveries: the office hands an order to a courier, the courier confirms
 * each step from their phone, the order and the customer follow.
 *
 * ── One source of truth for the order's status ──────────────────────────────
 *
 * Every step here moves the order through `Order::transitionTo()`, the same
 * method the office's own buttons use, so the status history, the tracking
 * page and the customer's emails and texts are exactly what they would be
 * had a member of staff pressed the button. The delivery row adds what the
 * office never had: who has the parcel, the proof at the door, and why a
 * delivery did not happen.
 *
 * ── A courier can only move forward ─────────────────────────────────────────
 *
 * Assigned → picked up → out for delivery → delivered. A courier cannot
 * undo a step, cancel, or touch an order that is not theirs; those are the
 * office's calls, from the admin. A failed attempt stays on the row with its
 * reason and the office decides what happens next.
 */
class CourierService
{
    public const PROOF_DISK = 'local';

    public const PROOF_DIRECTORY = 'deliveries';

    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly OrderNotifier $notifier,
    ) {}

    // ── The office ───────────────────────────────────────────────────────────

    /**
     * Hand an order to a courier. Reassigning replaces the courier on the
     * same row; the previous attempts stay counted.
     */
    public function assign(Order $order, User $courier, ?User $by = null, ?string $notes = null): Delivery
    {
        if (! $courier->can('deliveries.courier')) {
            throw new RuntimeException('That account is not a courier. Give it the Courier role first.');
        }

        if ($order->is_pickup || ! $order->requiresDelivery()) {
            throw new RuntimeException('This order is not for delivery.');
        }

        if (! $order->status->isPaid() || in_array($order->status, [OrderStatus::Delivered, OrderStatus::Collected, OrderStatus::Completed, OrderStatus::Refunded], true)) {
            throw new RuntimeException('This order is not waiting to be delivered.');
        }

        $delivery = DB::transaction(function () use ($order, $courier, $by, $notes): Delivery {
            $delivery = Delivery::query()->firstOrNew(['order_id' => $order->getKey()]);
            $previous = $delivery->courier_id;

            $delivery->fill([
                'courier_id' => $courier->getKey(),
                'assigned_by' => $by?->getKey(),
                'status' => Delivery::STATUS_ASSIGNED,
                'assigned_at' => now(),
                'office_notes' => $notes ?: $delivery->office_notes,
                'failed_at' => null,
                'failure_reason' => null,
            ])->save();

            $order->recordStatusChange($order->status, $by, ($previous && $previous !== $courier->getKey() ? 'Reassigned to ' : 'Assigned to ').$courier->name.' for delivery.');

            return $delivery;
        });

        $this->tellCourier($delivery->load('courier', 'order'));

        return $delivery;
    }

    public function cancel(Delivery $delivery, ?User $by = null, string $reason = ''): void
    {
        $delivery->forceFill(['status' => Delivery::STATUS_CANCELLED])->save();
        $delivery->order?->recordStatusChange($delivery->order->status, $by, 'Delivery cancelled.'.($reason !== '' ? ' '.$reason : ''));
    }

    // ── The courier ──────────────────────────────────────────────────────────

    public function pickedUp(Delivery $delivery, User $courier): void
    {
        $this->own($delivery, $courier);
        $this->expect($delivery, [Delivery::STATUS_ASSIGNED, Delivery::STATUS_FAILED]);

        $order = $delivery->order;

        DB::transaction(function () use ($delivery, $courier, $order): void {
            $delivery->forceFill(['status' => Delivery::STATUS_PICKED_UP, 'picked_up_at' => now()])->save();

            if (in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Packed], true)) {
                $order->transitionTo(OrderStatus::Shipped, $courier, 'Picked up by '.$courier->name.'.');
                $this->notifier->shipped($order, $courier->name);
            } else {
                $order->recordStatusChange($order->status, $courier, 'Picked up again by '.$courier->name.'.');
            }
        });
    }

    public function outForDelivery(Delivery $delivery, User $courier): void
    {
        $this->own($delivery, $courier);
        $this->expect($delivery, [Delivery::STATUS_ASSIGNED, Delivery::STATUS_PICKED_UP, Delivery::STATUS_FAILED]);

        $order = $delivery->order;

        DB::transaction(function () use ($delivery, $courier, $order): void {
            $delivery->forceFill([
                'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
                'picked_up_at' => $delivery->picked_up_at ?? now(),
                'out_for_delivery_at' => now(),
            ])->save();

            if ($order->status !== OrderStatus::OutForDelivery) {
                if (! in_array($order->status, [OrderStatus::Shipped], true)) {
                    $order->transitionTo(OrderStatus::Shipped, $courier, 'Picked up by '.$courier->name.'.');
                    $this->notifier->shipped($order, $courier->name);
                }

                $order->transitionTo(OrderStatus::OutForDelivery, $courier, 'Out for delivery with '.$courier->name.'.');
            }
        });
    }

    /**
     * Delivered — the proof is what the courier records at the door: who
     * took it, a note, a photograph, and where the phone was.
     */
    public function delivered(Delivery $delivery, User $courier, string $recipientName, ?string $note = null, ?UploadedFile $photo = null, ?float $lat = null, ?float $lng = null): void
    {
        $this->own($delivery, $courier);
        $this->expect($delivery, [Delivery::STATUS_ASSIGNED, Delivery::STATUS_PICKED_UP, Delivery::STATUS_OUT_FOR_DELIVERY, Delivery::STATUS_FAILED]);

        if ((bool) setting('courier.require_photo', false) && $photo === null) {
            throw new RuntimeException((string) setting('courier.photo_required_message', __('A photograph of the delivered parcel is required.')));
        }

        $path = $photo ? $this->storeProof($delivery, $photo) : null;
        $order = $delivery->order;

        DB::transaction(function () use ($delivery, $courier, $order, $recipientName, $note, $path, $lat, $lng): void {
            $delivery->forceFill([
                'status' => Delivery::STATUS_DELIVERED,
                'attempts' => $delivery->attempts + 1,
                'picked_up_at' => $delivery->picked_up_at ?? now(),
                'delivered_at' => now(),
                'recipient_name' => $recipientName,
                'proof_note' => $note,
                'proof_photo_path' => $path ?? $delivery->proof_photo_path,
                'proof_lat' => $lat,
                'proof_lng' => $lng,
            ])->save();

            $order->transitionTo(OrderStatus::Delivered, $courier, 'Delivered by '.$courier->name.' to '.$recipientName.'.');
        });
    }

    /** Could not deliver: the reason stays on the row; the office is told. */
    public function failed(Delivery $delivery, User $courier, string $reason): void
    {
        $this->own($delivery, $courier);
        $this->expect($delivery, [Delivery::STATUS_ASSIGNED, Delivery::STATUS_PICKED_UP, Delivery::STATUS_OUT_FOR_DELIVERY]);

        $order = $delivery->order;

        DB::transaction(function () use ($delivery, $courier, $order, $reason): void {
            $delivery->forceFill([
                'status' => Delivery::STATUS_FAILED,
                'attempts' => $delivery->attempts + 1,
                'failed_at' => now(),
                'failure_reason' => $reason,
            ])->save();

            $order->recordStatusChange($order->status, $courier, 'Delivery attempt by '.$courier->name.' failed: '.$reason);
        });

        $this->tellOfficeOfFailure($delivery->refresh()->load('courier', 'order'));
    }

    // ── Proof photographs ────────────────────────────────────────────────────

    /**
     * On the private disk, never the public one: a photograph of somebody's
     * door is not media-library content. Read back through a signed route
     * for staff who may see the delivery.
     */
    private function storeProof(Delivery $delivery, UploadedFile $photo): string
    {
        $extension = strtolower($photo->getClientOriginalExtension() ?: 'jpg');

        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) {
            $extension = 'jpg';
        }

        $path = self::PROOF_DIRECTORY.'/'.$delivery->ulid.'-'.now()->format('YmdHis').'.'.$extension;

        Storage::disk(self::PROOF_DISK)->put($path, (string) file_get_contents($photo->getRealPath()));

        return $path;
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    private function own(Delivery $delivery, User $courier): void
    {
        if ($delivery->courier_id !== $courier->getKey()) {
            throw new RuntimeException('This delivery is not yours.');
        }
    }

    /** @param array<int, string> $statuses */
    private function expect(Delivery $delivery, array $statuses): void
    {
        if (! in_array($delivery->status, $statuses, true)) {
            throw new RuntimeException('This delivery is '.strtolower($delivery->label()).'; that step is not open.');
        }
    }

    // ── Messages ─────────────────────────────────────────────────────────────

    private function tellCourier(Delivery $delivery): void
    {
        $courier = $delivery->courier;
        $order = $delivery->order;

        if ($courier === null || $order === null || blank($courier->email)) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('delivery.assigned', (string) $courier->email, [
                'courier_name' => $courier->name,
                'order_reference' => $order->reference,
                'delivery_name' => (string) ($order->delivery_name ?: $order->customer_name),
                'delivery_address' => $this->addressLine($order),
                'delivery_phone' => (string) ($order->delivery_phone ?: $order->customer_phone),
                'portal_url' => route('courier.show', $delivery),
            ], [
                'to_name' => $courier->name,
                'related' => $order,
                'user_id' => $courier->getKey(),
                'idempotency_key' => 'delivery.assigned:'.$delivery->ulid.':'.$courier->getKey().':'.now()->format('YmdHi'),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function tellOfficeOfFailure(Delivery $delivery): void
    {
        $to = (string) (setting('contact.email_shop') ?: setting('contact.email_general') ?: '');
        $order = $delivery->order;

        if ($to === '' || $order === null) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('delivery.failed', $to, [
                'order_reference' => $order->reference,
                'courier_name' => (string) $delivery->courier?->name,
                'reason' => (string) $delivery->failure_reason,
                'attempts' => (string) $delivery->attempts,
                'delivery_name' => (string) ($order->delivery_name ?: $order->customer_name),
                'delivery_phone' => (string) ($order->delivery_phone ?: $order->customer_phone),
                'admin_url' => url('/scghf-office/orders/'.$order->ulid),
            ], [
                'related' => $order,
                'idempotency_key' => 'delivery.failed:'.$delivery->ulid.':'.$delivery->attempts,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The address as one line, for a message. */
    public function addressLine(Order $order): string
    {
        return collect([
            $order->delivery_address,
            $order->delivery_landmark ? __('near').' '.$order->delivery_landmark : null,
            $order->delivery_area,
            $order->delivery_city,
            $order->delivery_region,
            $order->delivery_gps ? 'GPS '.$order->delivery_gps : null,
        ])->filter()->implode(', ');
    }
}
