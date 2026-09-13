<?php

declare(strict_types=1);

namespace App\Shop;

use App\Communications\MessageDispatcher;
use App\Enums\OrderStatus;
use App\Models\DigitalDownloadToken;
use App\Models\Donor;
use App\Models\Invoice;
use App\Models\IssuedTicket;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Subscriber;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Telling the customer their money arrived.
 *
 * ── The template existed; nothing sent it ───────────────────────────────────
 *
 * `order.confirmation` has been seeded since Phase 3 with a full set of
 * variables and no caller. An order marked paid by the webhook produced no
 * email, no invoice, and a confirmation page promising both.
 *
 * ── Idempotent, because settlement is ───────────────────────────────────────
 *
 * A replayed webhook and a reconciliation run both call `onPaymentSettled()`
 * again. The invoice issuer returns the existing document, and the outbox's
 * idempotency key refuses a second confirmation for the same order.
 *
 * ── A failure here never undoes a paid order ────────────────────────────────
 *
 * The money is in and the stock is committed. A broken template must not
 * throw into the webhook path and have Paystack redeliver a payment that has
 * already been counted, so every step is reported rather than rethrown.
 */
final class OrderNotifier
{
    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly InvoiceIssuer $invoices,
    ) {}

    public function confirm(Order $order): void
    {
        if (! $order->status->isPaid()) {
            return;
        }

        $invoice = null;
        $order->loadMissing('items.product');

        if (! $order->goodsTotal()->isZero()) {
            try {
                $invoice = $this->invoices->issue($order);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $this->email($order, $invoice, 'order.confirmation:'.$order->reference);
        } catch (Throwable $e) {
            report($e);
        }

        $this->downloads($order);
        $this->tickets($order);
    }

    /**
     * Send the confirmation again, on request.
     *
     * A fresh idempotency key each time, so the outbox does not refuse it as
     * a duplicate of the one sent at settlement — which is exactly what it is,
     * and exactly what the customer who says it never arrived is asking for.
     */
    public function resend(Order $order): void
    {
        if (! $order->status->isPaid()) {
            return;
        }

        $this->email(
            $order,
            $order->invoice ?? $this->invoices->issue($order),
            'order.confirmation:'.$order->reference.':'.now()->timestamp,
        );
    }

    /**
     * The files somebody paid for, as expiring links.
     *
     * Sent once per order and only when a token exists — which is only when
     * the order is paid, because that is when tokens are made.
     */
    public function downloads(Order $order): void
    {
        $order->load('downloadTokens.item');

        if ($order->downloadTokens->isEmpty()) {
            return;
        }

        $links = $order->downloadTokens->map(fn (DigitalDownloadToken $t): string => sprintf(
            '<li><a href="%s">%s</a></li>',
            e(route('shop.download', $t)),
            e($t->item?->product_name ?? __('Download')),
        ))->implode("\n");

        try {
            $this->dispatcher->queueEmail('order.download', $order->customer_email, [
                'customer_name' => $order->customer_name,
                'order_reference' => $order->reference,
                'download_links' => new HtmlString('<ul>'.$links.'</ul>'),
                'expires_on' => $order->downloadTokens->min('expires_at')?->format('j F Y'),
                'download_limit' => (string) $order->downloadTokens->min('max_downloads'),
            ], [
                'to_name' => $order->customer_name,
                'related' => $order,
                'user_id' => $order->user_id,
                'idempotency_key' => 'order.download:'.$order->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The tickets somebody paid for, one code per admission, grouped by event. */
    public function tickets(Order $order): void
    {
        $order->load('issuedTickets.event');

        if ($order->issuedTickets->isEmpty()) {
            return;
        }

        foreach ($order->issuedTickets->groupBy('event_id') as $tickets) {
            $event = $tickets->first()->event;

            $list = $tickets->map(fn (IssuedTicket $t): string => sprintf(
                '<li><strong>%s</strong> — %s</li>',
                e($t->code),
                e($t->holder_name),
            ))->implode("\n");

            try {
                $this->dispatcher->queueEmail('order.tickets', $order->customer_email, [
                    'customer_name' => $order->customer_name,
                    'order_reference' => $order->reference,
                    'event_name' => $event?->title ?? '',
                    'event_date' => $event?->starts_at?->format('l j F Y, H:i') ?? '',
                    'event_venue' => $event?->venue_name ?? '',
                    'tickets' => new HtmlString('<ul>'.$list.'</ul>'),
                ], [
                    'to_name' => $order->customer_name,
                    'related' => $order,
                    'user_id' => $order->user_id,
                    'idempotency_key' => 'order.tickets:'.$order->reference.':'.$event?->getKey(),
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Every other change of state, in one template.
     *
     * The status line and the sentence come from the enum; the frame comes
     * from the CMS. Dispatch keeps its own message because it carries the
     * courier and the tracking reference.
     */
    public function status(Order $order, OrderStatus $status): void
    {
        if (! $status->isPaid() && $status !== OrderStatus::Cancelled) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('order.status', $order->customer_email, [
                'customer_name' => $order->customer_name,
                'order_reference' => $order->reference,
                'status_label' => __($status->label()),
                'status_message' => __($status->customerMessage()),
                'order_url' => $order->trackingUrl(),
            ], [
                'to_name' => $order->customer_name,
                'related' => $order,
                'user_id' => $order->user_id,
                'idempotency_key' => 'order.status:'.$order->reference.':'.$status->value.':'.now()->format('YmdHi'),
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        if (filled($order->customer_phone) && in_array($status, [OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::Collected], true)) {
            try {
                $this->dispatcher->queueSms('order.status', $order->customer_phone, [
                    'order_reference' => $order->reference,
                    'status_label' => __($status->label()),
                ], [
                    'related' => $order,
                    'idempotency_key' => 'order.status.sms:'.$order->reference.':'.$status->value,
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * The customer reached the payment page and never came back.
     *
     * ⚠ OFF unless `shop.abandoned_checkout_reminder` is switched on, and
     * then only to somebody who has agreed to email from the foundation — a
     * confirmed newsletter subscriber or a donor who ticked the box. A
     * checkout tick is consent to hold details for the order, not to be
     * written to about it afterwards. Once per order.
     */
    public function abandoned(Order $order): void
    {
        if (! setting('shop.abandoned_checkout_reminder', false) || $order->reminded_at !== null || blank($order->customer_email)) {
            return;
        }

        $email = mb_strtolower(trim((string) $order->customer_email));

        $consented = Donor::query()->where('email', $email)->where('consent_email', true)->exists()
            || Subscriber::query()->where('email', $email)->where('status', Subscriber::STATUS_CONFIRMED)->exists();

        if (! $consented) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('order.abandoned', $order->customer_email, [
                'customer_name' => $order->customer_name ?: __('friend'),
                'order_reference' => $order->reference,
                'order_total' => $order->total,
                'resume_url' => route('shop.cart'),
            ], [
                'to_name' => $order->customer_name,
                'related' => $order,
                'user_id' => $order->user_id,
                'idempotency_key' => 'order.abandoned:'.$order->reference,
            ]);

            $order->forceFill(['reminded_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The parcel has left.
     *
     * Email and, where there is a phone number, the one-line SMS — for a
     * customer in Tamale waiting on a courier, the text is the one that gets
     * read. Both transactional: they report on an order the customer placed.
     */
    public function shipped(Order $order, string $courier, ?string $tracking = null): void
    {
        try {
            $this->dispatcher->queueEmail('order.shipped', $order->customer_email, [
                'customer_name' => $order->customer_name,
                'order_reference' => $order->reference,
                'courier' => $courier,
                'tracking_reference' => $tracking,
            ], [
                'to_name' => $order->customer_name,
                'related' => $order,
                'user_id' => $order->user_id,
                'idempotency_key' => 'order.shipped:'.$order->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        $phone = $order->delivery_phone ?? $order->customer_phone;

        if (blank($phone)) {
            return;
        }

        try {
            $this->dispatcher->queueSms('order.shipped', (string) $phone, [
                'order_reference' => $order->reference,
                'courier' => $courier,
            ], [
                'related' => $order,
                'user_id' => $order->user_id,
                'idempotency_key' => 'order.shipped.sms:'.$order->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function email(Order $order, ?Invoice $invoice, string $idempotencyKey): void
    {
        $order->loadMissing('items');

        $this->dispatcher->queueEmail('order.confirmation', $order->customer_email, [
            'customer_name' => $order->customer_name,
            'order_reference' => $order->reference,
            'order_total' => $order->total,
            'order_items' => $this->lines($order),
            'delivery_address' => match (true) {
                $order->is_pickup => __('Collection — we will let you know when it is ready.').($order->shippingZone?->pickup_address ? ' '.$order->shippingZone->pickup_address : ''),
                ! $order->requiresDelivery() => __('Nothing to deliver.'),
                default => $order->deliveryAddressLine(),
            },
            'invoice_number' => $invoice?->invoice_number,
            'order_url' => $order->trackingUrl(),
        ], [
            'to_name' => $order->customer_name,
            'related' => $order,
            'user_id' => $order->user_id,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * The lines, as a list. Marked as HTML so the renderer prints it as one in
     * the HTML body and as plain text in the text body.
     */
    private function lines(Order $order): HtmlString
    {
        $items = $order->items->map(fn (OrderItem $item): string => sprintf(
            '<li>%d × %s%s — %s</li>',
            $item->quantity,
            e($item->product_name),
            $item->variant_name ? ' ('.e($item->variant_name).')' : '',
            e($item->line_total->format()),
        ))->implode("\n");

        $totals = sprintf(
            '<li>%s: %s</li>%s<li>%s: %s</li><li><strong>%s: %s</strong></li>',
            e(__('Subtotal')),
            e($order->subtotal->format()),
            $order->discount->isPositive()
                ? sprintf('<li>%s: −%s</li>', e(__('Discount')), e($order->discount->format()))
                : '',
            e($order->is_pickup ? __('Collection') : __('Delivery')),
            e($order->shipping->isZero() ? __('free') : $order->shipping->format()),
            e(__('Total')),
            e($order->total->format()),
        );

        return new HtmlString("<ul>\n{$items}\n</ul>\n<ul>{$totals}</ul>");
    }
}
