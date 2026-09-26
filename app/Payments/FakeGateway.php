<?php

declare(strict_types=1);

namespace App\Payments;

use App\Jobs\ProcessPaymentWebhook;
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

    /**
     * Charge a stored authorization, deterministically.
     *
     * An authorization code containing `DECLINE` fails, so a test can exercise
     * the failure and suspension path without a mocking framework.
     */
    public function chargeAuthorization(PaymentTransaction $transaction, string $authorizationCode): GatewayResult
    {
        if (str_contains($authorizationCode, 'DECLINE')) {
            return GatewayResult::failed(
                status: 'failed',
                message: 'Card declined by the fake gateway.',
                gatewayReference: $transaction->gateway_reference,
                raw: ['fake' => true],
            );
        }

        return GatewayResult::succeeded(
            gatewayReference: $transaction->gateway_reference,
            amount: $transaction->amount,
            fee: FeeCalculator::fromConfig()->on($transaction->amount),
            channel: 'card',
            paidAt: now(),
            raw: ['fake' => true, 'recurring' => true],
            authorization: [
                'authorization_code' => $authorizationCode,
                'last4' => '4321',
                'card_type' => 'visa',
                'channel' => 'card',
            ],
        );
    }

    /**
     * A direct mobile-money charge, faked.
     *
     * Every network prompts on the handset (`pay_offline`), except that a phone
     * number ending in 00 is treated as a Telecel voucher flow (`send_otp`),
     * and one ending in 99 is declined — so every state the waiting page has to
     * show can be reached without a real wallet. The sandbox page is where the
     * "approve on phone" step is pressed, and it delivers the webhook.
     */
    public function chargeMobileMoney(PaymentTransaction $transaction, string $provider, string $phone): GatewayResult
    {
        if (str_ends_with($phone, '99')) {
            return GatewayResult::failed(
                status: 'failed',
                message: 'Insufficient balance (fake gateway).',
                gatewayReference: $transaction->gateway_reference,
                raw: ['fake' => true, 'provider' => $provider],
            );
        }

        if (str_ends_with($phone, '00')) {
            return GatewayResult::awaiting(
                status: 'send_otp',
                gatewayReference: $transaction->gateway_reference,
                message: 'Dial *110# to generate a voucher code and enter it here.',
                raw: ['fake' => true, 'provider' => $provider],
            );
        }

        return GatewayResult::awaiting(
            status: 'pay_offline',
            gatewayReference: $transaction->gateway_reference,
            message: 'Please approve the payment prompt on your phone.',
            raw: ['fake' => true, 'provider' => $provider],
        );
    }

    /** Any six digits are accepted; "000000" is refused, so the wrong-code path can be seen. */
    public function submitOtp(PaymentTransaction $transaction, string $otp): GatewayResult
    {
        if ($otp === '000000') {
            return GatewayResult::failed(
                status: 'failed',
                message: 'The code was not accepted (fake gateway).',
                gatewayReference: $transaction->gateway_reference,
                raw: ['fake' => true],
            );
        }

        return GatewayResult::awaiting(
            status: 'pay_offline',
            gatewayReference: $transaction->gateway_reference,
            message: 'Code accepted. Approve the payment prompt on your phone.',
            raw: ['fake' => true],
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

    /**
     * Deliver a webhook for this transaction, exactly as Paystack would.
     *
     * ── Why this goes the long way round ────────────────────────────────────
     *
     * It would be one line to call `onPaymentSettled()` directly. It would also
     * mean the sandbox exercised none of the machinery that actually decides
     * whether a real payment is believed: the signature check, the raw event
     * store, the idempotency that stops a replayed event double-counting a
     * gift, and the queued processing.
     *
     * The point of a fake GATEWAY rather than a mock is that the path under
     * test is the path that ships. So the payload is built in Paystack's shape,
     * signed with the same HMAC-SHA512, and recorded through
     * `PaymentManager::recordWebhook()` — the same method the live webhook
     * controller calls.
     */
    public function deliverWebhook(PaymentTransaction $transaction, bool $successful = true): void
    {
        $payload = [
            'event' => $successful ? 'charge.success' : 'charge.failed',
            'data' => [
                'reference' => $transaction->gateway_reference,
                'status' => $successful ? 'success' : 'failed',
                // Paystack reports minor units, and so does this.
                'amount' => $transaction->amount->toMinor(),
                'currency' => $transaction->currency,
                'paid_at' => now()->toIso8601String(),
                'channel' => 'card',
                'fees' => FeeCalculator::fromConfig()->on($transaction->amount)->toMinor(),
                'authorization' => [
                    'authorization_code' => 'AUTH_fake_'.substr(md5($transaction->gateway_reference), 0, 10),
                    'last4' => '4242',
                    'reusable' => true,
                ],
                'customer' => ['email' => $transaction->customer_email],
                'fake' => true,
            ],
        ];

        $rawBody = (string) json_encode($payload, JSON_THROW_ON_ERROR);

        $manager = app(PaymentManager::class);

        $event = $manager->recordWebhook(
            rawBody: $rawBody,
            signature: self::sign($rawBody),
            sourceIp: '127.0.0.1',
        );

        if ($event->signature_valid && ! $event->isProcessed()) {
            ProcessPaymentWebhook::dispatch($event->id);
        }
    }
}
