<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Payments\Contracts\PaymentGateway;
use App\ValueObjects\Money;
use RuntimeException;

/**
 * A gateway that behaves like Paystack without one existing.
 *
 * The foundation's merchant account is not open yet, and waiting for it would
 * mean building donations, receipts, reconciliation and the whole admin surface
 * blind, then discovering the mistakes on a live site with real money.
 *
 * Deterministic, not random: the same reference always produces the same
 * outcome, so a test that passes today passes tomorrow. Outcomes are steered by
 * the reference itself, which lets a test ask for a failure or a mismatch
 * without a mocking framework:
 *
 *     ...-FAIL      the gateway declines
 *     ...-PENDING   still waiting on the donor's handset
 *     ...-SHORT     settles one pesewa light, to exercise the mismatch path
 *     anything else succeeds for exactly the expected amount
 *
 * Signature verification uses the same HMAC-SHA512 as the real thing, so the
 * webhook path under test is the webhook path that ships.
 */
final class FakeGateway implements PaymentGateway
{
    public function name(): string
    {
        return PaymentTransaction::GATEWAY_FAKE;
    }

    public function initialise(PaymentTransaction $transaction, array $options = []): GatewayResult
    {
        return GatewayResult::initialised(
            gatewayReference: $transaction->gateway_reference,
            authorizationUrl: url('/payments/fake/'.$transaction->gateway_reference),
            accessCode: 'fake_access_'.substr(md5($transaction->gateway_reference), 0, 12),
            raw: ['fake' => true, 'reference' => $transaction->gateway_reference],
        );
    }

    public function verify(string $gatewayReference): GatewayResult
    {
        $transaction = PaymentTransaction::where('gateway_reference', $gatewayReference)->first();

        if ($transaction === null) {
            return GatewayResult::failed(
                status: 'failed',
                message: 'Unknown reference.',
                gatewayReference: $gatewayReference,
            );
        }

        if (str_ends_with($gatewayReference, '-FAIL')) {
            return GatewayResult::failed(
                status: 'failed',
                message: 'Declined by the fake gateway.',
                gatewayReference: $gatewayReference,
                raw: ['fake' => true],
            );
        }

        if (str_ends_with($gatewayReference, '-PENDING')) {
            return GatewayResult::failed(
                status: 'pending',
                message: 'Awaiting authorisation on the customer handset.',
                gatewayReference: $gatewayReference,
                raw: ['fake' => true],
            );
        }

        // Deliberately one pesewa short, so the mismatch path has a way to be
        // exercised without hand-building a payload.
        $amount = str_ends_with($gatewayReference, '-SHORT')
            ? $transaction->amount->minus(Money::ofMinor(1, $transaction->currency))
            : $transaction->amount;

        return GatewayResult::succeeded(
            gatewayReference: $gatewayReference,
            amount: $amount,
            fee: FeeCalculator::fromConfig()->on($transaction->amount),
            channel: 'mobile_money',
            paidAt: now(),
            raw: ['fake' => true, 'reference' => $gatewayReference],
            authorization: [
                'authorization_code' => 'AUTH_fake'.substr(md5($gatewayReference), 0, 10),
                'last4' => '4321',
                'card_type' => 'mobile_money',
                'bank' => 'MTN',
                'channel' => 'mobile_money',
            ],
        );
    }

    public function refund(Refund $refund): GatewayResult
    {
        return GatewayResult::succeeded(
            gatewayReference: 'fake_refund_'.$refund->ulid,
            amount: $refund->amount,
            raw: ['fake' => true],
        );
    }

    /**
     * The same HMAC-SHA512 check the real gateway uses.
     *
     * Faking this to always return true would leave the single most
     * security-critical line in the payments module untested, which defeats the
     * purpose of having a fake at all.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $secret = (string) config('payments.paystack.webhook_secret');

        if ($secret === '') {
            throw new RuntimeException(
                'No webhook secret configured. Even the fake gateway verifies signatures — '
                .'set PAYSTACK_SECRET_KEY to any non-empty value locally.'
            );
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }

    /** Helper for tests and local development: sign a body the way Paystack would. */
    public static function sign(string $rawBody): string
    {
        return hash_hmac('sha512', $rawBody, (string) config('payments.paystack.webhook_secret'));
    }
}
