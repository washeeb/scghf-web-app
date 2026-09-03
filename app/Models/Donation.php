<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Contracts\Payable;
use App\Enums\DonationStatus;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A gift.
 *
 * APPEND-ONLY. There is no `deleted_at` and no path that edits a completed
 * donation: a correction is a refund row or an adjusting entry. A ledger whose
 * rows can change is a ledger nobody can audit.
 *
 * @property DonationStatus $status
 */
class Donation extends Model implements Payable
{
    use HasFactory;
    use HasUlids;
    use LogsActivity;

    protected $fillable = [
        'reference', 'donor_id', 'user_id', 'cause_id', 'division_id', 'subscription_id',
        'amount', 'fee', 'fee_covered_by_donor', 'net', 'currency', 'status',
        'channel', 'momo_network', 'is_anonymous',
        'tribute_type', 'tribute_name', 'tribute_message', 'tribute_notify_email',
        'donor_name', 'donor_email', 'donor_phone',
        'consent_email', 'consent_sms', 'consent_text', 'consent_ip', 'consent_at',
        'notes', 'recorded_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'currency' => 'GHS',
        'fee_minor' => 0,
        'deductible_amount_minor' => 0,
        'fee_covered_by_donor' => false,
        'is_anonymous' => false,
        'consent_email' => false,
        'consent_sms' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DonationStatus::class,
            'amount' => MoneyCast::class.':amount_minor,currency',
            'fee' => MoneyCast::class.':fee_minor,currency',
            'net' => MoneyCast::class.':net_minor,currency',
            'deductible_amount' => MoneyCast::class.':deductible_amount_minor,currency',
            'fee_covered_by_donor' => 'boolean',
            'is_anonymous' => 'boolean',
            'consent_email' => 'boolean',
            'consent_sms' => 'boolean',
            'consent_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            // A date, not a datetime: nobody records the minute a cash gift
            // changed hands at an event, and pretending otherwise invents
            // precision the record does not have.
            'received_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $donation): void {
            $donation->reference ??= self::generateReference();

            // Denormalised from the cause so division reporting needs no join.
            if ($donation->division_id === null && $donation->cause_id !== null) {
                $donation->division_id = $donation->cause?->division_id;
            }

            // Net defaults to the gross until the gateway tells us the fee.
            if ($donation->net_minor === null) {
                $donation->net_minor = $donation->amount_minor;
            }
        });

        static::updating(function (self $donation): void {
            /*
             * Append-only, enforced. The amount, the cause and the reference on
             * a COMPLETED gift are what an acknowledgement already in a donor's
             * hands says — changing them would make the ledger disagree with a
             * document the foundation has issued.
             */
            /*
             * getRawOriginal, not getOriginal: `status` is cast to an enum, so
             * getOriginal() hands back a DonationStatus instance and the
             * comparison against the string silently never matched — which
             * left this guard doing nothing at all.
             */
            if ($donation->getRawOriginal('status') === DonationStatus::Completed->value) {
                $frozen = array_intersect(
                    array_keys($donation->getDirty()),
                    ['amount_minor', 'cause_id', 'reference', 'currency', 'deductible_amount_minor'],
                );

                if ($frozen !== []) {
                    throw new RuntimeException(
                        'A completed donation is append-only. Cannot change: '
                        .implode(', ', $frozen).'. Record a refund or an adjusting entry instead.'
                    );
                }
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Donations are never deleted. The ledger is append-only — refund it instead.'
            );
        });
    }

    /** `SCGHF-D-…`, quotable over the phone with no ambiguous characters. */
    public static function generateReference(): string
    {
        return 'SCGHF-D-'.Str::upper(substr(Str::ulid()->toBase32(), -10));
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

    /** @return BelongsTo<Donor, $this> */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    /** @return BelongsTo<Division, $this> */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** @return HasMany<DonationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DonationItem::class);
    }

    /**
     * The standing commitment this gift came from, if any.
     *
     * Null for a one-off. Set both on the first gift that established a
     * subscription and on every cycle it produces, so a donor's recurring
     * history is one query.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return MorphOne<PaymentTransaction, $this> */
    public function transaction(): MorphOne
    {
        return $this->morphOne(PaymentTransaction::class, 'payable');
    }

    /** @return HasOne<DonationReceipt, $this> */
    public function receipt(): HasOne
    {
        return $this->hasOne(DonationReceipt::class);
    }

    // ── The Payable contract ─────────────────────────────────────────────────

    public function chargeableAmount(): Money
    {
        return $this->amount;
    }

    public function payerEmail(): ?string
    {
        return $this->donor_email ?? $this->donor?->email;
    }

    /**
     * The money arrived and matched.
     *
     * **Idempotent, and it has to be.** Paystack retries, reconciliation
     * re-verifies, and an administrator can re-check by hand — so this runs
     * more than once for the same gift, and the second run must not increment
     * the cause total again.
     *
     * The guard is the status check plus a single transaction: either the
     * donation moves to completed and every total moves with it, or nothing
     * does.
     */
    public function onPaymentSettled(PaymentTransaction $transaction): void
    {
        if ($this->status === DonationStatus::Completed) {
            return;
        }

        DB::transaction(function () use ($transaction): void {
            /*
             * Re-read inside the transaction with a row lock. Two workers
             * processing a duplicate webhook simultaneously would otherwise
             * both pass the status check above and both increment the total.
             */
            $donation = static::query()->lockForUpdate()->find($this->getKey());

            if ($donation === null || $donation->status === DonationStatus::Completed) {
                return;
            }

            $fee = $transaction->fee ?? Money::zero($donation->currency);

            $donation->forceFill([
                'status' => DonationStatus::Completed,
                'fee_minor' => $fee->toMinor(),
                // What the foundation actually keeps, from the fee the gateway
                // ACTUALLY charged — not our model of it.
                'net_minor' => max(0, $donation->amount_minor - $fee->toMinor()),
                'paid_at' => $transaction->paid_at ?? now(),
                'channel' => $transaction->channel ?? $donation->channel,
                'paystack_reference' => $transaction->gateway_reference,
            ])->save();

            $donation->cause?->recordDonation($donation);
            $donation->donor?->recordDonation($donation);

            $this->setRawAttributes($donation->getAttributes(), true);
        });
    }

    public function onPaymentFailed(PaymentTransaction $transaction): void
    {
        if ($this->status === DonationStatus::Completed) {
            // A late failure must never erase a completed gift.
            return;
        }

        $this->forceFill([
            'status' => DonationStatus::Failed,
            'failed_at' => now(),
            'paystack_reference' => $transaction->gateway_reference,
        ])->save();
    }

    /**
     * The donor opened the payment page and never came back.
     *
     * Distinct from a decline, and the distinction is worth keeping: it decides
     * whether following the gift up is likely to be welcome or annoying.
     */
    public function onPaymentAbandoned(PaymentTransaction $transaction): void
    {
        if ($this->status === DonationStatus::Completed) {
            return;
        }

        $this->forceFill([
            'status' => DonationStatus::Abandoned,
            'paystack_reference' => $transaction->gateway_reference,
        ])->save();
    }

    /**
     * The gateway settled something we did not expect.
     *
     * Held, not completed and not failed. The money may well have been taken,
     * so telling the donor it failed would be wrong, and completing it would
     * credit a figure nobody verified.
     */
    public function onPaymentMismatch(PaymentTransaction $transaction): void
    {
        $this->forceFill([
            'status' => DonationStatus::NeedsReview,
            'paystack_reference' => $transaction->gateway_reference,
            'notes' => trim((string) $this->notes."\n".$transaction->mismatch_reason),
        ])->save();

        Log::critical('Donation held for review after a payment mismatch.', [
            'donation' => $this->reference,
            'transaction' => $transaction->ulid,
        ]);
    }

    // ── Money ────────────────────────────────────────────────────────────────

    /**
     * The deductible subtotal, summed from the items.
     *
     * A SUM OVER ITEMS, not a flag on the parent, because one gift can be part
     * deductible and part not — GH₵ 300 to a qualifying cause and GH₵ 200 to
     * one that is not is a single GH₵ 500 donation with two different tax
     * treatments.
     */
    public function recalculateDeductible(): Money
    {
        $minor = (int) $this->items()->where('is_tax_deductible', true)->sum('amount_minor');

        $this->forceFill(['deductible_amount_minor' => $minor])->save();

        return Money::ofMinor($minor, $this->currency);
    }

    public function deductibleAmount(): Money
    {
        return $this->deductible_amount ?? Money::zero($this->currency);
    }

    public function nonDeductibleAmount(): Money
    {
        return $this->amount->minus($this->deductibleAmount());
    }

    /** Whether any part of this gift may carry deductibility wording. */
    public function isPartlyDeductible(): bool
    {
        return $this->deductibleAmount()->isPositive();
    }

    /**
     * Assert the items sum exactly to the donation.
     *
     * The reconciliation that makes designated giving trustworthy. Money::allocate()
     * distributes the remainder a pesewa at a time precisely so this holds, and
     * a failure here means a gift has been split into parts that do not add back
     * up to what the donor gave.
     */
    public function assertItemsReconcile(): void
    {
        $sum = (int) $this->items()->sum('amount_minor');

        if ($sum !== $this->amount_minor) {
            throw new RuntimeException(sprintf(
                'Donation %s does not reconcile: items sum to %s but the gift was %s.',
                $this->reference,
                Money::ofMinor($sum, $this->currency)->format(),
                $this->amount->format(),
            ));
        }
    }

    /** What the donor sees as the giver's name, honouring anonymity. */
    public function publicDonorName(): string
    {
        return $this->is_anonymous ? 'Anonymous' : (string) ($this->donor_name ?? 'A supporter');
    }

    public function isCompleted(): bool
    {
        return $this->status === DonationStatus::Completed;
    }

    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->where('status', DonationStatus::Completed->value);
    }

    #[Scope]
    protected function needingReview(Builder $query): void
    {
        $query->where('status', DonationStatus::NeedsReview->value);
    }

    /** Gifts in a financial year, which starts 1 January for this foundation. */
    #[Scope]
    protected function inFinancialYear(Builder $query, int $year): void
    {
        $query->whereBetween('paid_at', [
            now()->setDate($year, 1, 1)->startOfDay(),
            now()->setDate($year, 12, 31)->endOfDay(),
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_minor', 'cause_id', 'paid_at', 'paystack_reference'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('donation');
    }
}
