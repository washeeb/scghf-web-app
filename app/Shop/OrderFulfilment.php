<?php

declare(strict_types=1);

namespace App\Shop;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Cause;
use App\Models\DigitalDownloadToken;
use App\Models\Donation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Payments\DonationService;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What a paid order delivers that is not a parcel.
 *
 * ── Each product type has its own consequence ───────────────────────────────
 *
 *   physical  — stock is committed and somebody packs it (Order::commitStock)
 *   digital   — a download token per line, emailed as a signed, expiring,
 *               limited link
 *   donation  — the line becomes a real donation to the product's appeal,
 *               receipted and counted as giving
 *   ticket    — tickets to the event, one per unit (TicketIssuer)
 *
 * ── Idempotent, because settlement is replayed ──────────────────────────────
 *
 * The webhook is processed at least once, the reconciliation sweep verifies
 * again, and Finance can replay an event by hand. A token that already
 * exists for the line is left alone; a donation is keyed on the line by a
 * unique index. Running this twice delivers nothing twice.
 *
 * ── Reports, not throws ─────────────────────────────────────────────────────
 *
 * Called after the order is paid, outside the settlement transaction. A
 * broken template or a missing file must not fail the webhook and have the
 * gateway redeliver a payment that has already been counted.
 */
final class OrderFulfilment
{
    public function __construct(
        private readonly DonationService $donations,
        private readonly TicketIssuer $tickets,
    ) {}

    public function fulfil(Order $order): void
    {
        if (! $order->status->isPaid()) {
            return;
        }

        $order->loadMissing(['items.product.cause', 'items.product.eventTicket.event', 'downloadTokens']);

        foreach ($order->items as $item) {
            $product = $item->product;

            if ($product === null) {
                continue;
            }

            try {
                match (true) {
                    $product->isDigital() => $this->issueDownload($order, $item),
                    $product->isDonation() => $this->recordDonation($order, $item),
                    $product->isTicket() => $this->tickets->issueForLine($order, $item),
                    default => null,
                };
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($order->donation_minor > 0) {
            try {
                $this->recordAddedGift($order);
            } catch (Throwable $e) {
                report($e);
            }
        }

        /*
         * Nothing to pack: the order is done the moment it is paid. Quietly —
         * the confirmation, the download or the tickets are the message, and
         * a second email saying "completed" a minute later is noise.
         */
        if (! $order->requiresDelivery() && $order->status === OrderStatus::Paid) {
            $order->forceFill(['status' => OrderStatus::Completed, 'delivered_at' => now()])->save();
            $order->recordStatusChange(OrderStatus::Paid, null, 'Nothing to deliver.');
        }
    }

    /** One token per line, however many copies were bought: the file is the same file. */
    private function issueDownload(Order $order, OrderItem $item): void
    {
        $product = $item->product;

        if ($product->download_media_id === null) {
            report(new \RuntimeException("Digital product [{$product->slug}] has no file to deliver on order {$order->reference}."));

            return;
        }

        if ($order->downloadTokens->contains(fn (DigitalDownloadToken $t): bool => $t->order_item_id === $item->getKey())) {
            return;
        }

        DigitalDownloadToken::create([
            'order_id' => $order->getKey(),
            'order_item_id' => $item->getKey(),
            'media_id' => $product->download_media_id,
            'max_downloads' => max(1, (int) $product->download_limit) * max(1, $item->quantity),
            'expires_at' => now()->addDays(max(1, (int) $product->download_days)),
        ]);
    }

    /**
     * The gift added at checkout, to the General Fund, once.
     *
     * Keyed on the order with no line: a "sponsor a meal" line has its own
     * row by `order_item_id`, and this one is the row with `order_id` set and
     * `order_item_id` null.
     */
    private function recordAddedGift(Order $order): void
    {
        if (Donation::query()->where('order_id', $order->getKey())->whereNull('order_item_id')->exists()) {
            return;
        }

        $this->settleShopDonation($order, null, $order->donation, null, 'GIFT');
    }

    /**
     * "Sponsor a meal" becomes a donation the moment the order is paid.
     *
     * A settled transaction row of its own, on the offline gateway — the money
     * came through the ORDER's payment, and reconciliation must not go looking
     * for a second settlement. The donation's own status and receipt then
     * behave exactly like any other gift: the appeal's total moves, the donor
     * record is matched or made, the receipt is issued. The customer's tick at
     * checkout is the consent to hold their details; nothing here opts them in
     * to anything.
     */
    private function recordDonation(Order $order, OrderItem $item): void
    {
        if (Donation::query()->where('order_item_id', $item->getKey())->exists()) {
            return;
        }

        $this->settleShopDonation($order, $item, Money::ofMinor((int) $item->line_total_minor, $item->currency), $item->product->cause, (string) $item->getKey());
    }

    /** One settled donation, from the order's own payment. */
    private function settleShopDonation(Order $order, ?OrderItem $item, Money $amount, ?Cause $cause, string $suffix): void
    {
        DB::transaction(function () use ($order, $item, $amount, $cause, $suffix): void {
            $donation = $this->donations->create([
                'amount' => $amount,
                'cause' => $cause,
                'channel' => 'shop',
                'source' => 'shop',
                'user_id' => $order->user_id,
                'donor_name' => $order->customer_name,
                'donor_email' => $order->customer_email,
                'donor_phone' => $order->customer_phone,
                'consent_text' => __('Details held to fulfil order :reference.', ['reference' => $order->reference]),
                'consent_ip' => null,
            ]);

            $donation->forceFill([
                'order_id' => $order->getKey(),
                'order_item_id' => $item?->getKey(),
                'public_message' => null,
            ])->save();

            $transaction = PaymentTransaction::create([
                'payable_type' => $donation->getMorphClass(),
                'payable_id' => $donation->getKey(),
                'gateway' => PaymentTransaction::GATEWAY_OFFLINE,
                'gateway_reference' => 'ORDER-'.$order->reference.'-'.$suffix,
                'amount' => $donation->amount,
                'currency' => $donation->currency,
                'status' => PaymentStatus::Pending,
                'channel' => 'shop',
                'customer_email' => $donation->donor_email,
                'initialised_at' => now(),
            ]);

            $transaction->settle(
                amountPaid: $donation->amount,
                paidAt: $order->paid_at ?? now(),
                payload: ['shop' => true, 'order' => $order->reference, 'product' => $item?->product?->slug, 'quantity' => $item?->quantity],
                fee: Money::zero($donation->currency),
            );

            $donation->onPaymentSettled($transaction->refresh());
        });
    }
}
