<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Money going back out.
 *
 * Its own row, never a negative donation. A signed amount column destroys the
 * ability to sum a ledger meaningfully — "total raised" and "total refunded"
 * are different questions, and one column answers neither well.
 *
 * Two people are recorded: who requested it and who approved it. They are
 * allowed to differ, and a single `created_by` could not express an approval at
 * all. Money leaving a charity is exactly where that separation earns its keep.
 */
class Refund extends Model
{
    use HasFactory;
    use HasUlids;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'payment_transaction_id', 'gateway_reference', 'amount', 'currency',
        'status', 'reason', 'requested_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_REQUESTED,
        'currency' => 'GHS',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':amount_minor,currency',
            'response_payload' => 'array',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $refund): void {
            $transaction = $refund->transaction;

            if ($transaction === null) {
                throw new RuntimeException('A refund must belong to a payment transaction.');
            }

            if (! $transaction->status->isSettled()) {
                throw new RuntimeException(
                    'Only a settled payment can be refunded. This transaction is '
                    .$transaction->status->value.'.'
                );
            }

            $requested = $refund->amount;

            if ($requested === null || ! $requested->isPositive()) {
                throw new RuntimeException('A refund must be for a positive amount.');
            }

            /*
             * Refunding more than was taken is not a rounding problem, it is
             * money leaving that never came in. Checked against what is left
             * after earlier refunds, not against the original charge.
             */
            if ($requested->greaterThan($transaction->refundableAmount())) {
                throw new RuntimeException(sprintf(
                    'Cannot refund %s: only %s of this %s payment remains refundable.',
                    $requested->format(),
                    $transaction->refundableAmount()->format(),
                    $transaction->amount->format(),
                ));
            }

            if (blank($refund->reason)) {
                throw new RuntimeException(
                    'A refund needs a stated reason. It is money leaving the foundation, and '
                    .'an auditor will ask why.'
                );
            }
        });
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

    /** @return BelongsTo<PaymentTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Approve the refund, ready to be sent to the gateway.
     *
     * The approver may not be the requester. Not a policy preference — it is
     * the standard control on an outbound payment, and a system that allows
     * one person to do both has no control at all.
     */
    public function approve(User $approver): void
    {
        if ($this->requested_by !== null && $this->requested_by === $approver->getKey()) {
            throw new RuntimeException(
                'A refund cannot be approved by the person who requested it. '
                .'Ask a second authorised user to approve it.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
        ])->save();
    }

    public function markProcessed(?string $gatewayReference = null, array $payload = []): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSED,
            'gateway_reference' => $gatewayReference ?? $this->gateway_reference,
            'processed_at' => now(),
            'response_payload' => $payload ?: $this->response_payload,
        ])->save();
    }

    public function markFailed(string $reason, array $payload = []): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'failure_reason' => $reason,
            'response_payload' => $payload ?: $this->response_payload,
        ])->save();
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** Whether this refund returned the whole charge. */
    public function isFull(): bool
    {
        return $this->amount->equals($this->transaction->amount);
    }

    public function amountRefunded(): Money
    {
        return $this->amount;
    }

    #[Scope]
    protected function awaitingApproval(Builder $query): void
    {
        $query->where('status', self::STATUS_REQUESTED);
    }

    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->where('status', self::STATUS_PROCESSED);
    }
}
