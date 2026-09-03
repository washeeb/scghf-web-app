<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One change to stock, and why.
 *
 * Append-only, and enforced. The ledger IS the stock figure; the column on the
 * variant is a cache of it. A mutable counter silently loses history the first
 * time two orders race, and "we had twelve mugs yesterday and have nine today"
 * becomes unanswerable.
 *
 * `quantity` is SIGNED — the one place in this schema where a negative number
 * is correct, because a movement has a direction and a balance does not.
 */
class InventoryMovement extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const REASON_SALE = 'sale';

    public const REASON_RESTOCK = 'restock';

    public const REASON_ADJUSTMENT = 'adjustment';

    public const REASON_RETURN = 'return';

    public const REASON_HOLD = 'hold';

    public const REASON_HOLD_RELEASE = 'hold_release';

    public const REASON_WRITE_OFF = 'write_off';

    protected $fillable = [
        'product_variant_id', 'reason', 'quantity', 'balance_after',
        'reference', 'note', 'created_by', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException(
                'Inventory movements are append-only. A correction is a new adjustment row with '
                .'a note, not an edit — otherwise the ledger stops being evidence of anything.'
            );
        });

        static::deleting(function (): void {
            throw new RuntimeException(
                'Inventory movements are never deleted. Write the stock off with a reason instead.'
            );
        });
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Whether this row moved goods, as opposed to only reserving them. */
    public function movedGoods(): bool
    {
        return $this->quantity !== 0;
    }

    public function isHold(): bool
    {
        return in_array($this->reason, [self::REASON_HOLD, self::REASON_HOLD_RELEASE], true);
    }

    #[Scope]
    protected function affectingStock(Builder $query): void
    {
        $query->where('quantity', '!=', 0);
    }

    #[Scope]
    protected function forReference(Builder $query, string $reference): void
    {
        $query->where('reference', $reference);
    }
}
