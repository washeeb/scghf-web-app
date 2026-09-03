<?php

declare(strict_types=1);

namespace App\Payments;

use App\Contracts\Payable;
use App\Enums\PaymentStatus;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Payments\Contracts\PaymentGateway;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Orchestrates payments. The only thing controllers, jobs and Filament touch.
 *
 * Responsibilities, deliberately few:
 *
 *   - create a transaction and start a charge
 *   - record a webhook exactly as it arrived, before understanding it
 *   - process a webhook idempotently, and tell the payable what happened
 *
 * What it does NOT do is decide what a payment MEANS. That belongs to the
 * payable — a donation increments a cause total and issues an acknowledgement,
 * an order reserves stock. Putting either here would give the payment layer
 * opinions about both.
 */
final class PaymentManager
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PayloadScrubber $scrubber,
    ) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    /**
     * A unique, quotable gateway reference.
     *
     * Crockford base32 from a ULID: no I/1 or O/0 to confuse when a donor reads
     * it back over the phone, and sortable by creation time.
     */
    public static function generateReference(string $prefix = 'SCGHF-P'): string
    {
        return $prefix.'-'.Str::upper(substr(Str::ulid()->toBase32(), -12));
    }

    /**
     * Start a charge for something.
     *
     * The transaction row is written FIRST, inside a transaction, and only then
     * is the gateway called. The other order — call the gateway, then record it
     * — loses the reference entirely if the write fails, and a charge nobody
     * has a record of is a charge nobody can reconcile.
     *
     * @param  Model&Payable  $payable
     * @param  array<string, mixed>  $options
     */
    public function charge(Model $payable, array $options = []): PaymentTransaction
    {
        $amount = $payable->chargeableAmount();

        $this->assertChargeable($amount);

        $transaction = DB::transaction(fn (): PaymentTransaction => PaymentTransaction::create([
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'gateway' => $this->gateway->name(),
            'gateway_reference' => $options['reference'] ?? self::generateReference(),
            'amount' => $amount,
            'currency' => $amount->currency,
            'status' => PaymentStatus::Initialised,
            'customer_email' => $payable->payerEmail(),
            'channel' => $options['channel'] ?? null,
            'initialised_at' => now(),
            'request_payload' => $this->scrubber->scrub($options['metadata'] ?? []),
        ]));

        $result = $this->gateway->initialise($transaction, $options);

        if (! $result->successful) {
            $transaction->markFailed($result->message ?? 'Gateway rejected the initialisation.', $result->raw);

            return $transaction->refresh();
        }

        $transaction->forceFill([
            'status' => PaymentStatus::Pending,
            'authorization_url' => $result->authorizationUrl,
            'access_code' => $result->accessCode,
            'response_payload' => $result->raw,
        ])->save();

        return $transaction;
    }

    /**
     * Ask the gateway what happened and act on the answer.
     *
     * Used by the callback page, by the webhook handler and by reconciliation.
     * All three go through here so there is one definition of "verified" —
     * three implementations would eventually disagree, and the one that
     * disagreed would be the one that credited a donation twice.
     */
    public function verifyAndSettle(PaymentTransaction $transaction): PaymentStatus
    {
        if ($transaction->status->isFinal()) {
            return $transaction->status;
        }

        $result = $this->gateway->verify($transaction->gateway_reference);

        return $this->applyResult($transaction, $result);
    }

    /**
     * Apply a gateway result to a transaction and notify the payable.
     *
     * @return PaymentStatus the status after applying
     */
    public function applyResult(PaymentTransaction $transaction, GatewayResult $result): PaymentStatus
    {
        if ($result->isPending()) {
            // Mobile money sits here while the donor approves the prompt on
            // their handset. Normal, not a problem — and treating it as failure
            // would abandon live payments.
            if ($transaction->status === PaymentStatus::Initialised) {
                $transaction->forceFill(['status' => PaymentStatus::Pending])->save();
            }

            return PaymentStatus::Pending;
        }

        if (! $result->isSuccess()) {
            $transaction->markFailed($result->message ?? 'Payment was not successful.', $result->raw);
            $this->notifyPayable($transaction, PaymentStatus::Failed);

            return PaymentStatus::Failed;
        }

        if ($result->authorization !== []) {
            $transaction->recordInstrument($result->authorization);
        }

        if ($result->channel !== null) {
            $transaction->forceFill(['channel' => $result->channel])->save();
        }

        $status = $transaction->settle(
            amountPaid: $result->amount,
            paidAt: $result->paidAt,
            payload: $result->raw,
            fee: $result->fee,
        );

        $this->notifyPayable($transaction->refresh(), $status);

        return $status;
    }

    /**
     * Store a webhook exactly as it arrived.
     *
     * In this order, and the order is the point:
     *
     *   1. write the raw body — before parsing, so a malformed payload is still
     *      evidence rather than a 500 with nothing kept
     *   2. verify the signature and record the verdict
     *   3. return, so the controller can answer 200 immediately
     *
     * Processing happens later, on the queue. Paystack times out and retries if
     * the endpoint is slow, and doing the work inline is how one slow donation
     * receipt turns into four duplicate webhook deliveries.
     */
    public function recordWebhook(string $rawBody, ?string $signature, ?string $sourceIp = null): PaymentWebhookEvent
    {
        $valid = $this->gateway->verifySignature($rawBody, $signature);

        $parsed = json_decode($rawBody, true);
        $parsed = is_array($parsed) ? $parsed : [];

        $eventId = $this->extractEventId($parsed, $rawBody);

        /*
         * The unique index on `event_id` is the idempotency guarantee, so a
         * replay collides here and is answered with the row that already
         * exists. Handled as a race rather than checked first: two deliveries
         * arriving at once would both pass a check-then-insert.
         */
        $existing = PaymentWebhookEvent::where('event_id', $eventId)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return PaymentWebhookEvent::create([
                'gateway' => $this->gateway->name(),
                'event_id' => $eventId,
                'event_type' => $parsed['event'] ?? null,
                'gateway_reference' => $parsed['data']['reference'] ?? null,
                'raw_payload' => $rawBody,
                'signature' => $signature,
                'signature_valid' => $valid,
                'source_ip' => $sourceIp,
                'received_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Lost the race. The other request stored it; that row is the one.
            $existing = PaymentWebhookEvent::where('event_id', $eventId)->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * Act on a stored webhook. Safe to call repeatedly.
     *
     * Every guard here exists because Paystack WILL deliver the same event more
     * than once, and because an event that was never authenticated must never
     * move money.
     */
    public function processWebhook(PaymentWebhookEvent $event): void
    {
        if (! $event->signature_valid) {
            // Kept as evidence, never acted on. A run of these is the signal
            // that somebody is probing the endpoint.
            Log::warning('Webhook with an invalid signature was not processed.', [
                'event' => $event->id,
                'ip' => $event->source_ip,
            ]);

            return;
        }

        if ($event->isProcessed()) {
            return;
        }

        if (! $event->isHandled()) {
            // Stored and acknowledged, but not acted on. An unrecognised event
            // is evidence, not an error — Paystack adds new types without
            // warning, and failing on them would fill the queue with noise.
            $event->markProcessed();

            return;
        }

        try {
            $this->dispatchEvent($event);
            $event->markProcessed();
        } catch (Throwable $e) {
            $event->markFailed($e->getMessage());

            Log::error('Webhook processing failed.', [
                'event' => $event->id,
                'type' => $event->event_type,
                'attempts' => $event->attempts,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function dispatchEvent(PaymentWebhookEvent $event): void
    {
        $data = $event->data();
        $reference = (string) ($data['reference'] ?? $event->gateway_reference ?? '');

        if ($reference === '') {
            return;
        }

        $transaction = PaymentTransaction::where('gateway_reference', $reference)->first();

        if ($transaction === null) {
            /*
             * A webhook for a reference we have never seen. Not an error worth
             * retrying — it is almost always a test event from the Paystack
             * dashboard, or a charge started from another integration on the
             * same merchant account. Recorded and left alone.
             */
            Log::info('Webhook for an unknown payment reference.', ['reference' => $reference]);

            return;
        }

        match ($event->event_type) {
            'charge.success' => $this->applyResult(
                $transaction,
                $this->transactionResult($data, $reference, $event->payload()),
            ),
            'charge.failed' => $this->applyResult(
                $transaction,
                GatewayResult::failed(
                    status: 'failed',
                    message: (string) ($data['gateway_response'] ?? 'Payment failed.'),
                    gatewayReference: $reference,
                    raw: $this->scrubber->scrub($event->payload()),
                ),
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private function transactionResult(array $data, string $reference, array $payload): GatewayResult
    {
        /*
         * The real service knows how to read Paystack's transaction object, and
         * a webhook carries the same shape as a verify response — so the same
         * parser handles both. Two parsers would eventually disagree about what
         * a payment means.
         */
        if ($this->gateway instanceof PaystackService) {
            return $this->gateway->resultFromTransactionData(
                $data,
                $reference,
                $this->scrubber->scrub($payload),
            );
        }

        $currency = (string) ($data['currency'] ?? config('payments.currency', 'GHS'));

        return GatewayResult::succeeded(
            gatewayReference: $reference,
            amount: Money::ofMinor((int) ($data['amount'] ?? 0), $currency),
            fee: isset($data['fees']) ? Money::ofMinor((int) $data['fees'], $currency) : null,
            channel: $data['channel'] ?? null,
            paidAt: isset($data['paid_at']) ? Carbon::parse((string) $data['paid_at']) : null,
            raw: $this->scrubber->scrub($payload),
            authorization: $data['authorization'] ?? [],
        );
    }

    /**
     * Tell the payable what happened, if it wants to know.
     *
     * Failures here are logged, never rethrown into the payment path. The money
     * has already moved; a broken receipt template must not turn a successful
     * donation into an exception that leaves the transaction unsettled.
     */
    private function notifyPayable(PaymentTransaction $transaction, PaymentStatus $status): void
    {
        $payable = $transaction->payable;

        if (! $payable instanceof Payable) {
            return;
        }

        try {
            match (true) {
                $status->isSettled() => $payable->onPaymentSettled($transaction),
                $status->needsReview() => $payable->onPaymentMismatch($transaction),
                default => $payable->onPaymentFailed($transaction),
            };
        } catch (Throwable $e) {
            Log::error('Payable failed to handle a settled payment.', [
                'transaction' => $transaction->ulid,
                'payable' => $transaction->payable_type.':'.$transaction->payable_id,
                'status' => $status->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A stable id for an event that may not carry one.
     *
     * Paystack sends an `id` on the data object. Where it is missing, a hash of
     * the body stands in — so a replayed identical body still collides on the
     * unique index and is still a no-op. Without this fallback, an event with
     * no id would insert a fresh row on every delivery and be processed every
     * time.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function extractEventId(array $parsed, string $rawBody): string
    {
        $id = $parsed['data']['id'] ?? $parsed['id'] ?? null;
        $type = $parsed['event'] ?? 'unknown';

        return $id !== null
            ? $type.':'.$id
            : $type.':sha256:'.hash('sha256', $rawBody);
    }

    private function assertChargeable(Money $amount): void
    {
        if (! $amount->isPositive()) {
            throw new RuntimeException('Cannot charge a zero or negative amount.');
        }

        $expected = (string) config('payments.currency', 'GHS');

        if ($amount->currency !== $expected) {
            throw new RuntimeException(
                "Refusing to charge in {$amount->currency}; this account transacts in {$expected}."
            );
        }
    }
}
