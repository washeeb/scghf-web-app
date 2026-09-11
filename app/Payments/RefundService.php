<?php

declare(strict_types=1);

namespace App\Payments;

use App\Contracts\Payable;
use App\Models\Donation;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\Models\User;
use App\Payments\Contracts\PaymentGateway;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Giving money back.
 *
 * ── Three steps, two people ─────────────────────────────────────────────────
 *
 * Requested by one user, approved by a different one, then sent to the
 * gateway. `Refund::approve()` refuses the requester as the approver. Money
 * leaving the foundation is the one action here where a second pair of eyes
 * is worth the delay, and the model enforces it so no screen can forget.
 *
 * ── Never more than was paid, less what was already returned ───────────────
 *
 * `PaymentTransaction::refundableAmount()` is the ceiling. A partial refund is
 * allowed; two partials that together exceed the payment are not.
 *
 * ── The gateway's word is final, and it arrives twice ───────────────────────
 *
 * `execute()` sends the refund and records what Paystack answered at once.
 * Paystack then confirms by webhook (`refund.processed` / `refund.failed`),
 * which `applyWebhook()` records against the same row — so a refund that
 * timed out on the API call is still closed by the webhook, and one that the
 * API accepted but the bank later bounced is marked failed rather than left
 * looking done.
 *
 * ── The payable is told once the money has actually gone ───────────────────
 *
 * A donation becomes `refunded` and its appeal's total is recalculated; an
 * order becomes `refunded`. Both happen on the processed confirmation, not on
 * the request.
 */
final class RefundService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function request(PaymentTransaction $transaction, Money $amount, string $reason, User $by): Refund
    {
        if (! $transaction->status->isSettled()) {
            throw new RuntimeException('Only a settled payment can be refunded.');
        }

        if (blank(trim($reason))) {
            throw new RuntimeException('A refund needs a reason. It is money leaving the foundation, and the ledger has to say why.');
        }

        $refundable = $transaction->refundableAmount();

        if (! $amount->isPositive() || $amount->greaterThan($refundable)) {
            throw new RuntimeException(sprintf(
                'A refund of %s is not possible: %s of this payment can still be refunded.',
                $amount->format(),
                $refundable->format(),
            ));
        }

        return Refund::create([
            'payment_transaction_id' => $transaction->getKey(),
            'amount' => $amount,
            'currency' => $amount->currency,
            'status' => Refund::STATUS_REQUESTED,
            'reason' => trim($reason),
            'requested_by' => $by->getKey(),
        ]);
    }

    /** Approve, then send. The model refuses the requester as approver. */
    public function approveAndExecute(Refund $refund, User $approver): Refund
    {
        $refund->approve($approver);

        return $this->execute($refund->refresh());
    }

    public function execute(Refund $refund): Refund
    {
        if ($refund->status !== Refund::STATUS_PENDING) {
            throw new RuntimeException('Only an approved, unsent refund can be sent to the gateway.');
        }

        $refund->load('transaction');

        $result = $this->gateway->refund($refund);

        if (! $result->successful) {
            $refund->markFailed($result->message ?? 'The gateway refused the refund.', $result->raw);

            return $refund->refresh();
        }

        /*
         * Accepted by the gateway. Paystack processes refunds asynchronously
         * and confirms by webhook; until then the row stays `pending` with the
         * gateway's reference on it, so the webhook can find it. The fake
         * gateway answers synchronously and is treated as processed at once.
         */
        $refund->forceFill([
            'gateway_reference' => $result->gatewayReference,
            'response_payload' => $result->raw,
        ])->save();

        if ($result->isSuccess() && ($result->raw['fake'] ?? false)) {
            $this->processed($refund, $result->raw);
        }

        return $refund->refresh();
    }

    /**
     * The webhook's verdict.
     *
     * @param  array<string, mixed>  $data  the event's `data` object
     */
    public function applyWebhook(string $eventType, array $data): void
    {
        $reference = (string) ($data['transaction_reference'] ?? $data['transaction']['reference'] ?? '');
        $refundId = isset($data['id']) ? (string) $data['id'] : null;

        $refund = null;

        if ($refundId !== null) {
            $refund = Refund::query()->where('gateway_reference', $refundId)->first();
        }

        if ($refund === null && $reference !== '') {
            $refund = Refund::query()
                ->whereHas('transaction', fn ($q) => $q->where('gateway_reference', $reference))
                ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_REQUESTED])
                ->latest('id')
                ->first();
        }

        if ($refund === null) {
            Log::warning('Refund webhook for a refund this application did not request.', [
                'event' => $eventType,
                'reference' => $reference,
                'refund' => $refundId,
            ]);

            return;
        }

        if ($eventType === 'refund.processed') {
            $this->processed($refund, $data, $refundId);

            return;
        }

        if ($refund->status !== Refund::STATUS_PROCESSED) {
            $refund->markFailed((string) ($data['reason'] ?? $data['status'] ?? 'Refund failed at the gateway.'), $data);
        }
    }

    /** @param array<string, mixed> $payload */
    private function processed(Refund $refund, array $payload, ?string $gatewayReference = null): void
    {
        if ($refund->status === Refund::STATUS_PROCESSED) {
            return;
        }

        $refund->markProcessed($gatewayReference, $payload);

        $payable = $refund->transaction?->payable;

        if ($payable instanceof Donation || $payable instanceof Order) {
            $payable->onRefunded($refund->refresh());
        }
    }
}
