<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\PaymentTransaction;
use App\ValueObjects\Money;

/**
 * Something money can be taken for — a donation, a shop order.
 *
 * The gateway boundary knows nothing about what it is charging for. It settles
 * a transaction and then tells the payable, which decides what that means:
 * a donation increments a cause total and issues an acknowledgement, an order
 * reserves stock and starts fulfilment. Putting that logic in the payment layer
 * would give it opinions about donations and about shop orders, and it should
 * have neither.
 *
 * Implementations must be IDEMPOTENT. Paystack retries, the reconciliation job
 * re-verifies, and an administrator can trigger a re-check by hand — so
 * `onPaymentSettled()` will be called more than once for the same payment, and
 * the second call must not double-count anything.
 */
interface Payable
{
    /** What to charge, in integer minor units, at the moment of charging. */
    public function chargeableAmount(): Money;

    /** The email the gateway should send a receipt to and key a customer on. */
    public function payerEmail(): ?string;

    /**
     * The money arrived and matched what was expected.
     *
     * Must be idempotent.
     */
    public function onPaymentSettled(PaymentTransaction $transaction): void;

    /** The gateway actively declined. */
    public function onPaymentFailed(PaymentTransaction $transaction): void;

    /**
     * The payer opened the payment page and never came back.
     *
     * Separate from failure, and not merely a naming preference. The gateway
     * declined nothing, so "failed" would misdescribe it — and more
     * practically, an abandoned SHOP order is holding stock that has to go back
     * on the shelf. Without this, a foundation with twelve mugs and eleven
     * abandoned checkouts shows as sold out.
     */
    public function onPaymentAbandoned(PaymentTransaction $transaction): void;

    /**
     * The gateway settled an amount or currency we did not expect.
     *
     * Deliberately separate from failure. The money may well have been taken,
     * so the payable must NOT be completed and must NOT be marked failed —
     * it is held for a human to look at.
     */
    public function onPaymentMismatch(PaymentTransaction $transaction): void;
}
