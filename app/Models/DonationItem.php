<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\TaxDeductibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One designated part of a gift.
 *
 * Exists for EVERY donation, including a single-destination one. Split giving
 * means a GH₵ 500 gift can be GH₵ 300 to a deductible cause and GH₵ 200 to one
 * that is not, so the deductible subtotal has to be a sum over rows. A nullable
 * special case for the simple gift would mean two code paths, and the rarely
 * exercised one would be the wrong one.
 *
 * `is_tax_deductible` is a SNAPSHOT. It records what TaxDeductibility said at
 * the moment of the gift — which requires both a qualifying cause and a current
 * GRA approval. If the approval lapses next year, an acknowledgement already in
 * a donor's hands must not silently change meaning.
 */
class DonationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'donation_id', 'cause_id', 'division_id', 'project_id',
        'amount', 'currency', 'is_tax_deductible', 'tax_approval_id', 'description',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'GHS',
        'is_tax_deductible' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class.':amount_minor,currency',
            'is_tax_deductible' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if ($item->division_id === null && $item->cause_id !== null) {
                $item->division_id = $item->cause?->division_id;
            }

            if ($item->amount_minor === null || $item->amount_minor <= 0) {
                throw new RuntimeException('A donation item must carry a positive amount.');
            }
        });

        static::updating(function (self $item): void {
            /*
             * The snapshot is the whole point. Letting it be rewritten would
             * mean an acknowledgement issued in 2026 could quietly stop
             * matching the record it was issued from.
             */
            if (array_key_exists('is_tax_deductible', $item->getDirty())) {
                throw new RuntimeException(
                    'The deductibility of a donation item is a snapshot taken at the time of the '
                    .'gift and cannot be changed. It is what the acknowledgement already says.'
                );
            }
        });
    }

    /** @return BelongsTo<Donation, $this> */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /** @return BelongsTo<Cause, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(Cause::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<TaxApproval, $this> */
    public function taxApproval(): BelongsTo
    {
        return $this->belongsTo(TaxApproval::class, 'tax_approval_id');
    }

    /**
     * Take the deductibility snapshot from the single gate.
     *
     * Asks TaxDeductibility rather than reading `causes.is_tax_deductible`,
     * because the flag on the cause is necessary but not sufficient — the
     * foundation must also hold a current written GRA approval. Reading the
     * column here would be exactly the shortcut that snapshots a claim the
     * foundation cannot support.
     */
    public static function snapshotDeductibility(Cause $cause, ?\DateTimeInterface $on = null): array
    {
        $tax = app(TaxDeductibility::class);

        if (! $tax->qualifies($cause, $on)) {
            return ['is_tax_deductible' => false, 'tax_approval_id' => null];
        }

        return [
            'is_tax_deductible' => true,
            // Which approval it was claimed under, so an old acknowledgement can
            // still be explained years later.
            'tax_approval_id' => $cause->tax_approval_id ?? $tax->approvalOn($on)?->getKey(),
        ];
    }
}
