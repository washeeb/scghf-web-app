<?php

declare(strict_types=1);

namespace App\Shop;

use App\Models\EventRegistration;
use App\Models\EventTicket;
use App\Models\IssuedTicket;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a paid ticket line into admissions.
 *
 * ── The registration is the person, the tickets are the admissions ─────────
 *
 * Registrations are unique per event and email, so a buyer who already
 * registered — free, last month — has tickets added to that record rather
 * than a second one refused by the index. The headcount and the ticket
 * type's `sold` figure move by the quantity, once, under a lock.
 *
 * ── Idempotent by (line, seq) ───────────────────────────────────────────────
 *
 * A replayed settlement finds the tickets already issued and issues none.
 * Sold-out is checked at BASKET time (`EventTicket::purchaseRejectionReason`
 * through the variant); by the time the order is paid the money has been
 * taken, and the honest outcome of an oversell is a ticket and a phone call,
 * not a refund nobody asked for.
 */
final class TicketIssuer
{
    public function issueForLine(Order $order, OrderItem $item): void
    {
        $product = $item->product;
        $type = $product?->eventTicket;

        if ($type === null || $type->event === null) {
            throw new RuntimeException("Ticket product [{$product?->slug}] is not linked to an event ticket on order {$order->reference}.");
        }

        if (IssuedTicket::query()->where('order_item_id', $item->getKey())->exists()) {
            return;
        }

        DB::transaction(function () use ($order, $item, $type): void {
            /** @var EventTicket $locked */
            $locked = EventTicket::query()->lockForUpdate()->findOrFail($type->getKey());
            $event = $locked->event;

            $registration = EventRegistration::query()
                ->where('event_id', $event->getKey())
                ->where('email', mb_strtolower(trim($order->customer_email)))
                ->first();

            if ($registration === null) {
                $registration = EventRegistration::create([
                    'event_id' => $event->getKey(),
                    'user_id' => $order->user_id,
                    'name' => $order->customer_name,
                    'email' => $order->customer_email,
                    'phone' => $order->customer_phone,
                    'guests' => max(0, $item->quantity - 1),
                    'status' => EventRegistration::STATUS_REGISTERED,
                    'consent_text' => __('Details held to fulfil order :reference.', ['reference' => $order->reference]),
                ]);
                $event->adjustHeadcount($item->quantity);
            } else {
                $registration->forceFill([
                    'guests' => (int) $registration->guests + $item->quantity,
                    'status' => $registration->status === EventRegistration::STATUS_CANCELLED
                        ? EventRegistration::STATUS_REGISTERED
                        : $registration->status,
                ])->save();
                $event->adjustHeadcount($item->quantity);
            }

            for ($seq = 1; $seq <= $item->quantity; $seq++) {
                IssuedTicket::create([
                    'event_id' => $event->getKey(),
                    'event_ticket_id' => $locked->getKey(),
                    'event_registration_id' => $registration->getKey(),
                    'order_id' => $order->getKey(),
                    'order_item_id' => $item->getKey(),
                    'seq' => $seq,
                    'holder_name' => $order->customer_name,
                ]);
            }

            EventTicket::whereKey($locked->getKey())->update([
                'sold' => DB::raw('sold + '.(int) $item->quantity),
                'updated_at' => now(),
            ]);
        });
    }
}
