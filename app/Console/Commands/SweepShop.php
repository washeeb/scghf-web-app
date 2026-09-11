<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Payments\PaymentManager;
use Illuminate\Console\Command;

/**
 * Put abandoned stock back on the shelf, and forget abandoned baskets.
 *
 * From cPanel cron, via the scheduler, every hour.
 *
 * ── Why hourly, when reconciliation already runs daily ──────────────────────
 *
 * The daily reconciliation writes off abandoned PAYMENTS, and an abandoned
 * order's stock goes back with them — but a day later. For a shop with twelve
 * mugs and a busy morning, eleven checkouts that were opened and closed at
 * 09:00 leave the mug "sold out" until tomorrow's 06:30 run. This sweep does
 * the same thing an hour after the customer left.
 *
 * ── The gateway is asked one last time ──────────────────────────────────────
 *
 * A mobile-money prompt approved forty minutes late is a real order. Before an
 * order is written off, its transaction is verified against the gateway, and
 * an order that turns out to be paid is settled rather than abandoned.
 *
 * ── Baskets are personal data with no purpose left ──────────────────────────
 *
 * An expired basket holds a coupon and, sometimes, an email address. Act 843
 * says data is kept only as long as it is needed for what it was collected
 * for, and thirty days after the last touch a basket is needed for nothing.
 */
class SweepShop extends Command
{
    protected $signature = 'scghf:sweep-shop
                            {--execute : Actually release stock and delete baskets}';

    protected $description = 'Release stock held by abandoned orders and delete expired baskets';

    public function handle(PaymentManager $payments): int
    {
        $execute = (bool) $this->option('execute');

        if (! $execute) {
            $this->warn('DRY RUN — nothing will be written. Add --execute to sweep.');
        }

        $released = 0;
        $recovered = 0;

        foreach (Order::query()->stale()->with('transaction')->cursor() as $order) {
            /** @var Order $order */
            if (! $execute) {
                $released++;

                continue;
            }

            $transaction = $order->transaction;

            if ($transaction !== null && $payments->verifyAndSettle($transaction) === PaymentStatus::Success) {
                $recovered++;

                continue;
            }

            if ($transaction !== null) {
                $transaction->refresh()->markAbandoned();
                $order->onPaymentAbandoned($transaction);
            } else {
                // Created, stock held, and the gateway was never reached.
                // `cancel()` releases the stock itself.
                $order->cancel('Checkout abandoned before payment started.');
            }

            $released++;
        }

        $expired = Cart::query()->expired();
        $baskets = $expired->count();

        if ($execute && $baskets > 0) {
            $expired->cursor()->each(fn (Cart $cart) => $cart->delete());
        }

        $this->line(sprintf(
            'Orders released: %d   Recovered as paid: %d   Baskets deleted: %d',
            $released,
            $recovered,
            $baskets,
        ));

        return self::SUCCESS;
    }
}
