<?php

declare(strict_types=1);

namespace App\Shop;

use App\Communications\MessageDispatcher;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
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

        try {
            $invoice = $this->invoices->issue($order);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            $this->email($order, $invoice, 'order.confirmation:'.$order->reference);
        } catch (Throwable $e) {
            report($e);
        }
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
            'delivery_address' => $order->is_pickup
                ? __('Collection — we will let you know when it is ready.')
                : collect([$order->delivery_address, $order->delivery_area, $order->delivery_region])->filter()->implode(', '),
            'invoice_number' => $invoice?->invoice_number,
            'order_url' => route('shop.order', $order),
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
