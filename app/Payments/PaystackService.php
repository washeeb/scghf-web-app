<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Payments\Contracts\PaymentGateway;
use App\ValueObjects\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The ONLY class in this application that talks to Paystack.
 *
 * Nothing else builds a Paystack URL, sets the Authorization header, or reads
 * Paystack's JSON shape. A controller that called the API directly would have
 * to know about kobo/pesewa conversion, about `data.status` versus
 * `data.gateway_response`, and about which fields are safe to store — and that
 * knowledge would then exist in as many places as there are call sites.
 *
 * Two things this class refuses to do:
 *
 *   - trust the browser redirect. `verify()` asks Paystack; the redirect only
 *     tells us the donor came back, not that the money arrived.
 *   - hand back a raw payload. Everything returns a normalised GatewayResult,
 *     already scrubbed.
 */
final class PaystackService implements PaymentGateway
{
    public function __construct(
        private readonly PayloadScrubber $scrubber,
    ) {}

    public function name(): string
    {
        return PaymentTransaction::GATEWAY_PAYSTACK;
    }

    public function initialise(PaymentTransaction $transaction, array $options = []): GatewayResult
    {
        $response = $this->client()->post('/transaction/initialize', array_filter([
            'email' => $transaction->customer_email,

            /*
             * Paystack takes the amount in the currency's SUBUNIT — pesewas for
             * GHS. This is already how the amount is stored, so there is no
             * conversion here and nothing to get wrong. That is the whole
             * reason money is integer minor units throughout.
             */
            'amount' => $transaction->amount->toMinor(),

            // Never omitted. Paystack rejects a transaction without it, and an
            // amount with no currency is meaningless data.
            'currency' => $transaction->currency,

            'reference' => $transaction->gateway_reference,
            'callback_url' => $options['callback_url'] ?? config('payments.paystack.callback_url'),
            'channels' => $options['channels'] ?? config('payments.paystack.channels'),
            'metadata' => $options['metadata'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== []));

        $body = $this->scrubber->scrub((array) $response->json());

        if (! $response->successful() || ($body['status'] ?? false) !== true) {
            return GatewayResult::failed(
                status: 'failed',
                message: (string) ($body['message'] ?? 'Paystack rejected the initialisation.'),
                gatewayReference: $transaction->gateway_reference,
                raw: $body,
            );
        }

        $data = $body['data'] ?? [];

        return GatewayResult::initialised(
            gatewayReference: (string) ($data['reference'] ?? $transaction->gateway_reference),
            authorizationUrl: $data['authorization_url'] ?? null,
            accessCode: $data['access_code'] ?? null,
            raw: $body,
        );
    }

    public function chargeAuthorization(PaymentTransaction $transaction, string $authorizationCode): GatewayResult
    {
        $response = $this->client()->post('/transaction/charge_authorization', [
            'authorization_code' => $authorizationCode,
            'email' => $transaction->customer_email,
            'amount' => $transaction->amount->toMinor(),
            'currency' => $transaction->currency,
            'reference' => $transaction->gateway_reference,
        ]);

        $body = $this->scrubber->scrub((array) $response->json());

        if (! $response->successful() || ($body['status'] ?? false) !== true) {
            return GatewayResult::failed(
                status: 'failed',
                message: (string) ($body['message'] ?? 'Paystack declined the stored authorization.'),
                gatewayReference: $transaction->gateway_reference,
                raw: $body,
            );
        }

        // Same shape as verify(), so the same parser reads it — two parsers
        // would eventually disagree about what a payment means.
        return $this->resultFromTransactionData(
            $body['data'] ?? [],
            $transaction->gateway_reference,
            $body,
        );
    }

    public function verify(string $gatewayReference): GatewayResult
    {
        $response = $this->client()->get('/transaction/verify/'.urlencode($gatewayReference));

        $body = $this->scrubber->scrub((array) $response->json());

        if (! $response->successful() || ($body['status'] ?? false) !== true) {
            return GatewayResult::failed(
                status: 'failed',
                message: (string) ($body['message'] ?? 'Paystack could not verify this reference.'),
                gatewayReference: $gatewayReference,
                raw: $body,
            );
        }

        return $this->resultFromTransactionData($body['data'] ?? [], $gatewayReference, $body);
    }

    /**
     * Build a normalised result from Paystack's transaction object.
     *
     * Shared by `verify()` and the webhook handler, which receive the same
     * shape. Parsing it in one place is what stops the two disagreeing about
     * what a payment means.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function resultFromTransactionData(array $data, string $gatewayReference, array $raw = []): GatewayResult
    {
        $status = (string) ($data['status'] ?? 'failed');

        if ($status !== 'success') {
            return GatewayResult::failed(
                status: $status,
                message: (string) ($data['gateway_response'] ?? 'Payment was not successful.'),
                gatewayReference: $gatewayReference,
                raw: $raw ?: $data,
            );
        }

        $currency = (string) ($data['currency'] ?? config('payments.currency', 'GHS'));

        return GatewayResult::succeeded(
            gatewayReference: (string) ($data['reference'] ?? $gatewayReference),
            amount: Money::ofMinor((int) ($data['amount'] ?? 0), $currency),
            // `fees` is what Paystack ACTUALLY took. Our FeeCalculator is only
            // a model for display; this is the number the ledger keeps.
            fee: isset($data['fees']) ? Money::ofMinor((int) $data['fees'], $currency) : null,
            channel: $data['channel'] ?? null,
            paidAt: isset($data['paid_at']) ? Carbon::parse((string) $data['paid_at']) : null,
            raw: $raw ?: $data,
            authorization: $this->safeAuthorization($data['authorization'] ?? []),
        );
    }

    public function refund(Refund $refund): GatewayResult
    {
        $response = $this->client()->post('/refund', [
            'transaction' => $refund->transaction->gateway_reference,
            'amount' => $refund->amount->toMinor(),
            'currency' => $refund->currency,
            'merchant_note' => $refund->reason,
        ]);

        $body = $this->scrubber->scrub((array) $response->json());

        if (! $response->successful() || ($body['status'] ?? false) !== true) {
            return GatewayResult::failed(
                status: 'failed',
                message: (string) ($body['message'] ?? 'Paystack rejected the refund.'),
                raw: $body,
            );
        }

        $data = $body['data'] ?? [];

        return GatewayResult::succeeded(
            gatewayReference: (string) ($data['id'] ?? $refund->ulid),
            amount: $refund->amount,
            raw: $body,
        );
    }

    /**
     * Verify a webhook signature.
     *
     * HMAC-SHA512 over the RAW request body, compared with `hash_equals()`.
     *
     * Two details that are the whole security of the endpoint:
     *
     *   - the raw body, not a re-encoded array. `json_encode(json_decode($b))`
     *     is not byte-identical to `$b`, and the signature is over the bytes.
     *   - `hash_equals()`, not `===`. A short-circuiting comparison leaks how
     *     many leading bytes were right, one request at a time.
     *
     * A missing or empty secret returns false rather than throwing: an
     * unconfigured webhook must reject everything, not accept everything.
     */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        $secret = (string) config('payments.paystack.webhook_secret');

        if ($secret === '') {
            Log::critical('Paystack webhook secret is not configured — every webhook will be rejected.');

            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }

    /**
     * Card and mobile-money metadata that is safe to keep.
     *
     * An allow-list, not a deny-list. Paystack's `authorization` object can
     * gain fields at any time, and a deny-list would store whatever is added
     * next by default — which is the wrong default when the subject is payment
     * instruments.
     *
     * @param  array<string, mixed>  $authorization
     * @return array<string, mixed>
     */
    private function safeAuthorization(array $authorization): array
    {
        return array_intersect_key($authorization, array_flip([
            'authorization_code', 'last4', 'card_type', 'brand', 'bank',
            'channel', 'exp_month', 'exp_year', 'country_code', 'reusable',
        ]));
    }

    private function client(): PendingRequest
    {
        $secret = (string) config('payments.paystack.secret_key');

        if ($secret === '') {
            throw new RuntimeException(
                'PAYSTACK_SECRET_KEY is not set. Set PAYMENT_DRIVER=fake to build against '
                .'fixtures, or supply the key — this service will not guess.'
            );
        }

        return Http::withToken($secret)
            ->baseUrl((string) config('payments.paystack.base_url'))
            ->timeout((int) config('payments.paystack.timeout', 20))
            ->acceptJson()
            ->asJson()
            // One retry, because a donor is waiting. More than that and the
            // donor is looking at a spinner while the request queues up behind
            // a gateway that is already struggling.
            ->retry(2, 250, throw: false);
    }
}
