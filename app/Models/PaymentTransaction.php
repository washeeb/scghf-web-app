<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\PaymentStatus;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * One charge at the gateway boundary.
 *
 * Append-only. A completed transaction is never edited or deleted; a correction
 * is a `Refund` row. That is what makes the ledger auditable.
 *
 * The table stores WHAT WE EXPECTED and WHAT ACTUALLY HAPPENED in separate
 * columns, and `settle()` compares them before it will mark anything paid. A
 * disagreement produces `mismatch` — never a silent success at the gateway's
 * number.
 *
 * @property PaymentStatus $status
 */
class PaymentTransaction extends Model
{
    use HasFactory;
    use HasUlids;

    public const GATEWAY_PAYSTACK = 'paystack';

    public const GATEWAY_FAKE = 'fake';

    public const GATEWAY_OFFLINE = 'offline';

    protected $fillable = [
        'payable_type', 'payable_id', 'gateway', 'gateway_reference',
        'amount', 'currency', 'status', 'channel', 'momo_network',
        'customer_email', 'customer_code', 'authorization_url', 'access_code',
        'initialised_at', 'request_payload',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'gateway' => self::GATEWAY_PAYSTACK,
        'currency' => 'GHS',
        'status' => 'initialised',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => MoneyCast::class.':amount_minor,currency',
            'amount_paid' => MoneyCast::class.':amount_paid_minor,currency_paid',
            'fee' => MoneyCast::class.':fee_minor,currency',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'initialised_at' => 'datetime',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    // ── Relationships ────────────────────────────────────────────────────────

    /** A donation, a shop order, or anything else that takes money. */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @return HasMany<PaymentWebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class, 'gateway_reference', 'gateway_reference');
    }

    // ── Settlement ───────────────────────────────────────────────────────────

    /**
     * Record what the gateway says happened, having checked it against what we
     * expected.
     *
     * **The single most important method in the payments module.** Everything
     * else can be retried or corrected; crediting a donation for an amount
     * nobody actually paid cannot.
     *
     * Four rules, in order:
     *
     *   1. A final transaction is never re-settled. A late `charge.failed`
     *      arriving after a `charge.success` must not erase a completed gift
     *      from a donor's history, and a replayed success must not double-count.
     *   2. The currency must match. A GHS charge settling in NGN is not a
     *      successful GHS charge.
     *   3. The amount must match EXACTLY. Not "close enough", not "at least" —
     *      a difference of one pesewa is a difference, and it means something
     *      is wrong upstream.
     *   4. Anything that fails 2 or 3 becomes `mismatch`, which raises an alert
     *      and waits for a human. It never auto-completes.
     *
     * @param  array<string, mixed>  $payload  already scrubbed
     */
    public function settle(
        Money $amountPaid,
        ?\DateTimeInterface $paidAt = null,
        array $payload = [],
        ?Money $fee = null,
    ): PaymentStatus {
        if ($this->status->isFinal()) {
            /*
             * Idempotent by design, and quietly so: Paystack retries, and a
             * second delivery of an event we have already acted on is normal
             * traffic rather than an error worth alerting on.
             */
            return $this->status;
        }

        $expected = $this->amount;

        if ($amountPaid->currency !== $expected->currency) {
            return $this->flagMismatch(sprintf(
                'Currency mismatch: expected %s, gateway settled %s.',
                $expected->currency,
                $amountPaid->currency,
            ), $amountPaid, $payload);
        }

        if (! $amountPaid->equals($expected)) {
            return $this->flagMismatch(sprintf(
                'Amount mismatch: expected %s, gateway settled %s.',
                $expected->format(),
                $amountPaid->format(),
            ), $amountPaid, $payload);
        }

        $this->forceFill([
            'status' => PaymentStatus::Success,
            'amount_paid_minor' => $amountPaid->toMinor(),
            'currency_paid' => $amountPaid->currency,
            'fee_minor' => $fee?->toMinor(),
            'paid_at' => $paidAt ?? now(),
            'verified_at' => now(),
            'response_payload' => $payload ?: $this->response_payload,
        ])->save();

        return PaymentStatus::Success;
    }

    /**
     * Record a mismatch and alert.
     *
     * Deliberately NOT a failure: the money may well have been taken. Calling
     * it `failed` would tell a donor their gift did not go through while the
     * foundation holds their money, which is the worse of the two wrong
     * answers.
     *
     * @param  array<string, mixed>  $payload
     */
    private function flagMismatch(string $reason, Money $amountPaid, array $payload): PaymentStatus
    {
        $this->forceFill([
            'status' => PaymentStatus::Mismatch,
            'amount_paid_minor' => $amountPaid->toMinor(),
            'currency_paid' => $amountPaid->currency,
            'mismatch_reason' => $reason,
            'response_payload' => $payload ?: $this->response_payload,
            'verified_at' => now(),
        ])->save();

        Log::critical('Payment amount or currency mismatch — held for review, NOT completed.', [
            'transaction' => $this->ulid,
            'gateway_reference' => $this->gateway_reference,
            'reason' => $reason,
        ]);

        return PaymentStatus::Mismatch;
    }

    public function markFailed(string $reason = '', array $payload = []): void
    {
        if ($this->status->isFinal()) {
            return;
        }

        $this->forceFill([
            'status' => PaymentStatus::Failed,
            'mismatch_reason' => $reason !== '' ? $reason : null,
            'response_payload' => $payload ?: $this->response_payload,
            'verified_at' => now(),
        ])->save();
    }

    /**
     * The donor opened the payment page and never came back.
     *
     * Distinct from `failed`, which means the gateway actively declined. The
     * difference matters when deciding whether it is worth following up.
     */
    public function markAbandoned(): void
    {
        if ($this->status->isFinal()) {
            return;
        }

        $this->forceFill(['status' => PaymentStatus::Abandoned])->save();
    }

    /**
     * Card and mobile-money metadata from the gateway.
     *
     * Refuses anything that looks like a full card number. Paystack does not
     * send one, but this class is the boundary and a boundary that trusts the
     * other side is not a boundary.
     */
    public function recordInstrument(array $authorization): void
    {
        $last4 = $authorization['last4'] ?? null;

        if ($last4 !== null && strlen((string) $last4) > 4) {
            throw new RuntimeException(
                'Refusing to store a card value longer than four digits. This application '
                .'never receives or stores card data — see the PCI DSS SAQ-A posture.'
            );
        }

        $this->forceFill(array_filter([
            'authorization_code' => $authorization['authorization_code'] ?? null,
            'card_last4' => $last4,
            'card_brand' => $authorization['card_type'] ?? $authorization['brand'] ?? null,
            'bank' => $authorization['bank'] ?? null,
            'channel' => $authorization['channel'] ?? $this->channel,
        ], fn ($value): bool => $value !== null))->save();
    }

    /** Total refunded so far, so a second refund cannot exceed the charge. */
    public function refundedAmount(): Money
    {
        $minor = (int) $this->refunds()
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_PROCESSED])
            ->sum('amount_minor');

        return Money::ofMinor($minor, $this->currency);
    }

    public function refundableAmount(): Money
    {
        if (! $this->status->isSettled()) {
            return Money::zero($this->currency);
        }

        return $this->amount->minus($this->refundedAmount());
    }

    #[Scope]
    protected function settled(Builder $query): void
    {
        $query->where('status', PaymentStatus::Success->value);
    }

    #[Scope]
    protected function needingReview(Builder $query): void
    {
        $query->where('status', PaymentStatus::Mismatch->value);
    }

    /** Open transactions old enough that the donor has clearly gone. */
    #[Scope]
    protected function stale(Builder $query): void
    {
        $minutes = (int) config('payments.reconciliation.abandon_after_minutes', 60);

        $query->whereIn('status', [PaymentStatus::Initialised->value, PaymentStatus::Pending->value])
            ->where('created_at', '<', now()->subMinutes($minutes));
    }
}
