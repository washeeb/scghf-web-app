<?php

declare(strict_types=1);

namespace App\Shop;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Payments\PaymentManager;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns a basket into an order and starts the charge.
 *
 * The order of operations is the design:
 *
 *   1. check every line is still available — before taking any money
 *   2. price it LIVE from the variants, never from the cart
 *   3. write the order and its snapshot lines in ONE transaction
 *   4. hold the stock
 *   5. reconcile the arithmetic
 *   6. only then call the gateway
 *
 * Step 5 before step 6 for the same reason a donation's designations are
 * reconciled first: a total that does not add up is a bug worth refusing to
 * charge for, not one to discover on an invoice.
 *
 * Step 4 before step 6 because the alternative — decrementing on payment only —
 * lets two customers buy the last mug while both are on the payment page.
 */
final class CheckoutService
{
    public function __construct(
        private readonly PaymentManager $payments,
    ) {}

    /**
     * Build an order from a basket.
     *
     * @param  array<string, mixed>  $details  customer and delivery details
     */
    public function createOrder(Cart $cart, array $details): Order
    {
        $cart->load('items.variant.product');

        if ($cart->isEmpty()) {
            throw new RuntimeException('There is nothing in the basket to check out.');
        }

        $unavailable = $cart->unavailableLines();

        if ($unavailable !== []) {
            /*
             * Checked here rather than after payment. A customer discovering at
             * the gateway that a mug went out of stock a fortnight ago has had
             * a worse experience than being told on the basket page — and the
             * foundation has taken money it cannot fulfil.
             */
            throw new RuntimeException(
                'These are no longer available: '.implode(', ', $unavailable)
                .'. Remove them to continue.'
            );
        }

        $subtotal = $cart->subtotal();
        $weight = $cart->totalWeightGrams();

        // A basket of downloads, tickets or gifts has nowhere to go: no zone,
        // no rate, no charge, and no address asked for.
        [$zone, $rate, $shipping] = $cart->requiresDelivery()
            ? $this->resolveShipping($details, $subtotal, $weight)
            : [null, null, Money::zero($subtotal->currency)];

        [$coupon, $discount] = $this->resolveCoupon(
            $cart,
            $subtotal,
            $shipping,
            $details['customer_email'] ?? null,
        );

        // The gift added at the last step. Not a line — it is not goods —
        // and never discounted by a coupon; the customer said what to give.
        $donation = $this->resolveDonation($details, $subtotal->currency);

        $total = $subtotal->plus($shipping)->minus($discount)->plus($donation);

        return DB::transaction(function () use (
            $cart, $details, $subtotal, $shipping, $discount, $donation, $total, $zone, $rate, $coupon
        ): Order {
            $order = Order::create([
                'user_id' => $details['user_id'] ?? $cart->user_id,
                'customer_name' => $details['customer_name'],
                'customer_email' => mb_strtolower(trim((string) $details['customer_email'])),
                'customer_phone' => $details['customer_phone'] ?? null,
                'status' => OrderStatus::Pending,
                'subtotal' => $subtotal,
                'shipping' => $shipping,
                'discount' => $discount,
                'donation' => $donation,
                'total' => $total,
                'currency' => $subtotal->currency,
                'coupon_id' => $coupon?->getKey(),
                'coupon_code' => $coupon?->code,
                'shipping_zone_id' => $zone?->getKey(),
                'shipping_rate_id' => $rate?->getKey(),
                // Snapshotted: a rate renamed or repriced later must not change
                // what this customer was told they were paying.
                'shipping_method' => $rate?->name ?? ($zone?->is_pickup ? 'Collection' : ($cart->requiresDelivery() ? null : 'Nothing to deliver')),
                'delivery_name' => $details['delivery_name'] ?? $details['customer_name'],
                'delivery_phone' => $details['delivery_phone'] ?? $details['customer_phone'] ?? null,
                'delivery_address' => $details['delivery_address'] ?? null,
                'delivery_area' => $details['delivery_area'] ?? null,
                'delivery_city' => $details['delivery_city'] ?? null,
                'delivery_region' => $details['delivery_region'] ?? null,
                'delivery_landmark' => $details['delivery_landmark'] ?? null,
                'delivery_gps' => isset($details['delivery_gps']) ? strtoupper(trim((string) $details['delivery_gps'])) ?: null : null,
                'delivery_notes' => $details['delivery_notes'] ?? null,
                'is_pickup' => (bool) ($zone?->is_pickup ?? false),
                'channel' => $details['channel'] ?? null,
            ]);

            foreach ($cart->items as $item) {
                // The unit price the basket showed — tiers and member price
                // included — so the order's lines add up to the subtotal it
                // was charged on.
                OrderItem::fromVariant($order, $item->variant, $item->quantity, $item->unitPrice());
            }

            $order->load('items');

            // Before anything leaves. A total that does not add up is a bug
            // worth refusing to charge for.
            $order->assertTotalsReconcile();

            $order->holdStock();

            if ($coupon !== null && $discount->isPositive()) {
                $coupon->redeem($discount, $order, $order->customer_email);
            }

            $order->recordStatusChange(null, null, 'Order placed.');

            return $order->refresh();
        });
    }

    /**
     * Build the order and hand back the transaction to send the customer to.
     *
     * @param  array<string, mixed>  $details
     * @return array{order: Order, transaction: PaymentTransaction}
     */
    public function start(Cart $cart, array $details): array
    {
        $order = $this->createOrder($cart, $details);

        $transaction = $this->payments->charge($order, [
            'reference' => $order->reference,
            'channel' => $details['channel'] ?? null,
            /*
             * Where the gateway sends the customer back to. Passed per
             * transaction rather than left to PAYSTACK_CALLBACK_URL, which is
             * the donation callback — a customer returning from paying for a
             * mug must not land on a page about a gift.
             */
            'callback_url' => $details['callback_url'] ?? null,
            'metadata' => [
                'order' => $order->reference,
                // Marked so a Paystack dashboard entry is identifiable as a
                // purchase rather than a donation at a glance.
                'type' => 'shop_order',
            ],
        ]);

        return ['order' => $order, 'transaction' => $transaction];
    }

    /**
     * Work out the delivery zone, rate and charge.
     *
     * @param  array<string, mixed>  $details
     * @return array{0: ShippingZone|null, 1: ShippingRate|null, 2: Money}
     */
    private function resolveShipping(array $details, Money $subtotal, int $weightGrams): array
    {
        if (! empty($details['is_pickup'])) {
            $zone = ShippingZone::query()->active()->where('is_pickup', true)->first();

            // Pickup is a zone with a zero rate, not a branch in the checkout.
            return [$zone, null, Money::zero($subtotal->currency)];
        }

        $region = $details['delivery_region'] ?? null;

        if (blank($region)) {
            throw new RuntimeException('A delivery region is needed to work out the delivery charge.');
        }

        $zone = ShippingZone::forRegion((string) $region);

        if ($zone === null) {
            throw new RuntimeException(sprintf(
                'The shop does not currently deliver to %s. Collection may be available instead.',
                $region,
            ));
        }

        $zone->load('rates');
        $rate = $zone->rateFor($subtotal, $weightGrams);

        if ($rate === null) {
            throw new RuntimeException(sprintf(
                'No delivery rate covers a %dg order to %s. Please contact the foundation.',
                $weightGrams,
                $region,
            ));
        }

        return [$zone, $rate, $rate->priceFor($subtotal)];
    }

    /**
     * The gift, if any, within the donation limits the giving form uses.
     *
     * @param  array<string, mixed>  $details
     */
    private function resolveDonation(array $details, string $currency): Money
    {
        $amount = $details['donation'] ?? null;

        if ($amount instanceof Money) {
            return $amount;
        }

        $minor = (int) round(((float) ($amount ?? 0)) * 100);

        if ($minor <= 0) {
            return Money::zero($currency);
        }

        $max = setting('donations.max_amount', 10_000_000);
        $max = $max instanceof Money ? $max->toMinor() : (int) $max;

        if ($minor > $max) {
            throw new RuntimeException('That gift is larger than the shop can take at once. Please give it on the donation page.');
        }

        return Money::ofMinor($minor, $currency);
    }

    /**
     * @return array{0: Coupon|null, 1: Money}
     */
    private function resolveCoupon(Cart $cart, Money $subtotal, Money $shipping, ?string $email): array
    {
        $coupon = $cart->coupon;

        if ($coupon === null) {
            return [null, Money::zero($subtotal->currency)];
        }

        $reason = $coupon->rejectionReason($subtotal, $email);

        if ($reason !== null) {
            // Refused rather than silently dropped. A customer who typed a code
            // and sees no discount at the payment page will assume the shop is
            // broken, which is worse than being told the code expired.
            throw new RuntimeException($reason);
        }

        return [$coupon, $coupon->discountFor($subtotal, $shipping)];
    }
}
