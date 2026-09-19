<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToDivision;
use App\Support\AuditLogger;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Money going out. The direction nothing else in this application recorded.
 *
 * Everything built so far records money coming IN — donations, orders,
 * subscriptions, receipts. A foundation is judged on what it does with it, and
 * until now there was nowhere to say that GH₵ 12,000 went to school fees for
 * forty children in the Northern Region.
 *
 * ── Separation of duties, enforced here rather than on a screen ─────────────
 *
 * Whoever REQUESTS a payment may not be whoever APPROVES it. In an organisation
 * where two or three people do everything, that is the single most effective
 * control against both fraud and honest error — and it is worth the friction
 * precisely because it is inconvenient for the person it constrains.
 *
 * It lives on the model because a gate that lives in one admin screen is a gate
 * that a console command, an import, or a future API route walks straight past.
 *
 * ── Evidence before paid ────────────────────────────────────────────────────
 *
 * A disbursement with no receipt, no signed collection slip and no bank advice
 * is the finding every audit opens with. `markPaid()` refuses without one.
 *
 * ── Append-only ─────────────────────────────────────────────────────────────
 *
 * No soft delete, like donations and receipts. A correction is a new row.
 *
 * @property Money|null $amount
 */
class Payout extends Model
{
    use BelongsToDivision;
    use HasFactory;
    use HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const CATEGORY_SCHOOL_FEES = 'school_fees';

    public const CATEGORY_MEDICAL = 'medical';

    public const CATEGORY_FOOD = 'food';

    public const CATEGORY_RENT = 'rent';

    public const CATEGORY_STIPEND = 'stipend';

    public const CATEGORY_SUPPLIER = 'supplier';

    public const CATEGORY_TRANSPORT = 'transport';

    public const CATEGORY_EQUIPMENT = 'equipment';

    protected $fillable = [
        'division_id', 'project_id', 'cause_id', 'beneficiary_id',
        'payee_name', 'payee_reference', 'amount', 'currency',
        'category', 'method', 'momo_network', 'purpose', 'notes',
        'evidence_media_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'category' => 'other',
        'method' => 'mobile_money',
        'currency' => 'GHS',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':amount_minor,currency',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payout): void {
            $payout->reference ??= 'SCGHF-PO-'.Str::upper(substr(Str::ulid()->toBase32(), -10));
        });

        static::saving(function (self $payout): void {
            /*
             * A payout attributed to nothing cannot be reported to a funder,
             * and "what did you spend our grant on?" is the question a funder
             * always asks. One of division, project or cause is required.
             */
            if ($payout->division_id === null
                && $payout->project_id === null
                && $payout->cause_id === null) {
                throw new RuntimeException(
                    'A payout must be attributed to a division, a project or a cause. An '
                    .'unattributed disbursement cannot be reported to a funder, and reporting '
                    .'to funders is what keeps the money coming.'
                );
            }

            if (! ($payout->amount instanceof Money) || ! $payout->amount->isPositive()) {
                throw new RuntimeException('A payout must be for a positive amount.');
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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    /** @return BelongsTo<Beneficiary, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Media, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'evidence_media_id');
    }

    // ── The workflow ─────────────────────────────────────────────────────────

    public function submit(User $requester): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw new RuntimeException('Only a draft payout can be submitted for approval.');
        }

        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'requested_by' => $requester->getKey(),
            'requested_at' => now(),
        ])->save();
    }

    /**
     * Approve a payment somebody else requested.
     *
     * The refusal below is the whole control. It is deliberately not
     * overridable, not configurable, and not skippable by a role — a
     * "Super Admin may self-approve" exception would be used on the first busy
     * afternoon and then always.
     */
    public function approve(User $approver): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new RuntimeException('Only a payout awaiting approval can be approved.');
        }

        if ($this->requested_by !== null && $approver->getKey() === $this->requested_by) {
            throw new RuntimeException(
                'A payout cannot be approved by the person who requested it. Two pairs of eyes on '
                .'money leaving the foundation is the single most effective control there is, and '
                .'it is worth the inconvenience.'
            );
        }

        if (! $approver->can('payouts.approve')) {
            throw new RuntimeException(
                'Approving a payout needs the `payouts.approve` permission.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
        ])->save();

        app(AuditLogger::class)->record(
            event: 'payout.approved',
            description: sprintf(
                'Approved %s to %s for %s.',
                (string) $this->amount, $this->payee_name, $this->purpose,
            ),
            subject: $this,
            causer: $approver,
        );
    }

    public function reject(User $approver, string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Rejecting a payout needs a reason.');
        }

        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ])->save();
    }

    /**
     * Record that the money actually went.
     *
     * Refuses without evidence. A disbursement with no receipt, no signed
     * collection slip and no bank advice is the finding every audit opens with
     * — and the moment to attach it is now, not "later", because later is when
     * it is lost.
     */
    public function markPaid(User $payer, ?Media $evidence = null): void
    {
        if ($this->status !== self::STATUS_APPROVED) {
            throw new RuntimeException('Only an approved payout can be marked paid.');
        }

        $evidenceId = $evidence?->getKey() ?? $this->evidence_media_id;

        if ($evidenceId === null) {
            throw new RuntimeException(
                'A payout cannot be marked paid without evidence — a receipt, a signed collection '
                .'slip or a bank advice. Attaching it now is the difference between a clean audit '
                .'and a finding.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_PAID,
            'paid_at' => now(),
            'paid_by' => $payer->getKey(),
            'evidence_media_id' => $evidenceId,
        ])->save();
    }

    public function cancel(string $reason): void
    {
        if ($this->status === self::STATUS_PAID) {
            throw new RuntimeException(
                'A payout that has been paid cannot be cancelled. The money has gone; a '
                .'correction is a new record, not an edit to this one.'
            );
        }

        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'rejection_reason' => $reason,
        ])->save();
    }

    // ── Reporting ────────────────────────────────────────────────────────────

    /**
     * What actually left the foundation over a period.
     *
     * Counts PAID only. An approved payout is a decision; money that has not
     * moved is not expenditure, and reporting it as such overstates what the
     * foundation has done.
     */
    public static function paidBetween(Carbon $from, Carbon $to, ?int $divisionId = null): Money
    {
        $total = static::query()
            ->where('status', self::STATUS_PAID)
            ->whereBetween('paid_at', [$from, $to])
            ->when($divisionId !== null, fn (Builder $q) => $q->where('division_id', $divisionId))
            ->sum('amount_minor');

        return Money::ofMinor((int) $total, (string) config('payments.currency', 'GHS'));
    }

    #[Scope]
    protected function awaitingApproval(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING)->orderBy('requested_at');
    }

    #[Scope]
    protected function paid(Builder $query): void
    {
        $query->where('status', self::STATUS_PAID);
    }
}
