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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The thing actually bought — a size, a colour, an SKU.
 *
 * Every product has at least one, even one with no choices to make. A nullable
 * "default variant" special case would mean two code paths through pricing,
 * stock and order lines, and the rarely-exercised one would be wrong.
 *
 * **Stock is a ledger.** `stock_on_hand` is a cache of the sum of
 * `inventory_movements`; `recalculateStock()` rebuilds it. Nothing writes the
 * cache directly except the methods here, and each of them writes a movement in
 * the same transaction.
 */
class ProductVariant extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'product_id', 'sku', 'name', 'options', 'price', 'compare_at_price',
        'member_price', 'price_tiers',
        'currency', 'tracks_stock', 'allow_backorder', 'weight_grams',
        'length_mm', 'width_mm', 'height_mm',
        'sort_order', 'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'GHS',
        'stock_on_hand' => 0,
        'stock_held' => 0,
        'tracks_stock' => true,
        'allow_backorder' => false,
        'sort_order' => 0,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'tracks_stock' => 'boolean',
            'allow_backorder' => 'boolean',
            'is_active' => 'boolean',
            'price' => MoneyCast::class.':price_minor,currency',
            'compare_at_price' => MoneyCast::class.':compare_at_price_minor,currency',
            'member_price' => MoneyCast::class.':member_price_minor,currency',
            'price_tiers' => 'array',
            'low_stock_alerted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $variant): void {
            if ($variant->price_minor !== null && $variant->price_minor < 0) {
                throw new RuntimeException('A price cannot be negative.');
            }

            // Only a physical thing sits on a shelf or weighs anything. A
            // download, a ticket or a sponsored meal cannot run out and cannot
            // be posted, whatever the form was told.
            $product = $variant->product ?? ($variant->product_id ? Product::find($variant->product_id) : null);

            if ($product !== null && ! $product->requiresDelivery()) {
                $variant->tracks_stock = false;
                $variant->allow_backorder = false;
                $variant->weight_grams = null;
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->orderByDesc('id');
    }

    // ── Pricing ──────────────────────────────────────────────────────────────

    /**
     * The unit price for this many, for this customer.
     *
     * Bulk tiers are by quantity: the highest `min_quantity` the order reaches
     * sets the unit price. The member price is for a signed-in customer.
     * Where both apply the customer gets the lower, because a price list that
     * charges a member more for buying ten is a price list nobody trusts.
     */
    public function priceFor(int $quantity = 1, bool $member = false): Money
    {
        $price = $this->price;

        foreach ($this->sortedTiers() as $tier) {
            if ($quantity >= $tier['min_quantity']) {
                $price = Money::ofMinor($tier['price_minor'], $this->currency);
            }
        }

        if ($member && $this->member_price !== null && $this->member_price->lessThan($price)) {
            $price = $this->member_price;
        }

        return $price;
    }

    /** @return list<array{min_quantity: int, price_minor: int}> */
    public function sortedTiers(): array
    {
        return collect($this->price_tiers ?? [])
            ->filter(fn ($t): bool => is_array($t) && (int) ($t['min_quantity'] ?? 0) > 1 && (int) ($t['price_minor'] ?? -1) >= 0)
            ->map(fn (array $t): array => ['min_quantity' => (int) $t['min_quantity'], 'price_minor' => (int) $t['price_minor']])
            ->sortBy('min_quantity')
            ->values()
            ->all();
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    /**
     * What can actually be sold right now.
     *
     * On-hand minus held. Held stock belongs to somebody sitting on a payment
     * page; selling it again is how two customers buy the last mug.
     */
    public function sellableQuantity(): int
    {
        if (! $this->tracks_stock) {
            return PHP_INT_MAX;
        }

        return max(0, $this->stock_on_hand - $this->stock_held);
    }

    public function isSellable(int $quantity = 1): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! $this->tracks_stock || $this->allow_backorder) {
            return true;
        }

        return $this->sellableQuantity() >= $quantity;
    }

    /**
     * Reserve stock for an order awaiting payment.
     *
     * A `hold` movement of zero quantity — it changes nothing on hand, because
     * the goods have not moved. What it changes is `stock_held`, and the row
     * exists so "why could nobody buy this yesterday" has an answer.
     */
    public function hold(int $quantity, string $reference): InventoryMovement
    {
        if ($quantity <= 0) {
            throw new RuntimeException('A hold must be for a positive quantity.');
        }

        return DB::transaction(function () use ($quantity, $reference): InventoryMovement {
            $variant = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $variant->isSellable($quantity)) {
                throw new RuntimeException(sprintf(
                    'Only %d of %s remain available; %d were requested.',
                    $variant->sellableQuantity(),
                    $variant->sku,
                    $quantity,
                ));
            }

            static::whereKey($variant->getKey())->update([
                'stock_held' => DB::raw('stock_held + '.$quantity),
            ]);

            return $this->recordMovement(
                InventoryMovement::REASON_HOLD,
                0,
                $reference,
                sprintf('%d held pending payment.', $quantity),
            );
        });
    }

    /** Give held stock back — the payment failed, or the order was abandoned. */
    public function releaseHold(int $quantity, string $reference): InventoryMovement
    {
        return DB::transaction(function () use ($quantity, $reference): InventoryMovement {
            static::whereKey($this->getKey())->update([
                // GREATEST guards against a double release taking it negative,
                // which a retried abandonment sweep would otherwise do.
                'stock_held' => DB::raw('GREATEST(0, CAST(stock_held AS SIGNED) - '.max(0, $quantity).')'),
            ]);

            return $this->recordMovement(
                InventoryMovement::REASON_HOLD_RELEASE,
                0,
                $reference,
                sprintf('%d released.', $quantity),
            );
        });
    }

    /**
     * Convert a hold into an actual sale.
     *
     * The stock leaves and the hold is dropped in one statement, so there is no
     * instant where the goods are both held and sold.
     */
    public function commitSale(int $quantity, string $reference): InventoryMovement
    {
        return DB::transaction(function () use ($quantity, $reference): InventoryMovement {
            static::whereKey($this->getKey())->update([
                'stock_on_hand' => DB::raw('stock_on_hand - '.$quantity),
                'stock_held' => DB::raw('GREATEST(0, CAST(stock_held AS SIGNED) - '.$quantity.')'),
            ]);

            return $this->recordMovement(
                InventoryMovement::REASON_SALE,
                -$quantity,
                $reference,
            );
        });
    }

    public function restock(int $quantity, string $reference = '', ?User $by = null): InventoryMovement
    {
        if ($quantity <= 0) {
            throw new RuntimeException('A restock must be for a positive quantity.');
        }

        return DB::transaction(function () use ($quantity, $reference, $by): InventoryMovement {
            static::whereKey($this->getKey())->update([
                'stock_on_hand' => DB::raw('stock_on_hand + '.$quantity),
            ]);

            return $this->recordMovement(InventoryMovement::REASON_RESTOCK, $quantity, $reference, null, $by);
        });
    }

    /**
     * Correct the count after a stock take.
     *
     * Requires a note. An adjustment with no explanation is indistinguishable
     * from theft in a ledger, and the point of the ledger is that it is not.
     */
    public function adjust(int $delta, string $note, ?User $by = null): InventoryMovement
    {
        if (blank($note)) {
            throw new RuntimeException(
                'A stock adjustment needs a note. An unexplained adjustment is '
                .'indistinguishable from a loss.'
            );
        }

        return DB::transaction(function () use ($delta, $note, $by): InventoryMovement {
            static::whereKey($this->getKey())->update([
                'stock_on_hand' => DB::raw('stock_on_hand + '.$delta),
                // Restocked above the line: the next time it runs low is news again.
                'low_stock_alerted_at' => $delta > 0 ? null : $this->low_stock_alerted_at,
            ]);

            return $this->recordMovement(InventoryMovement::REASON_ADJUSTMENT, $delta, null, $note, $by);
        });
    }

    /**
     * Rebuild the cached figure from the ledger.
     *
     * The ledger is the truth; this is a cache. Any disagreement is the cache
     * being wrong, and reconciliation reports it before correcting it.
     */
    public function recalculateStock(): int
    {
        $sum = (int) $this->movements()->sum('quantity');

        static::whereKey($this->getKey())->update(['stock_on_hand' => $sum]);

        $this->refresh();

        return $sum;
    }

    /** Whether the cache and the ledger agree. */
    public function stockReconciles(): bool
    {
        return (int) $this->movements()->sum('quantity') === (int) $this->stock_on_hand;
    }

    private function recordMovement(
        string $reason,
        int $quantity,
        ?string $reference = null,
        ?string $note = null,
        ?User $by = null,
    ): InventoryMovement {
        $this->refresh();

        return InventoryMovement::create([
            'product_variant_id' => $this->getKey(),
            'reason' => $reason,
            'quantity' => $quantity,
            'balance_after' => $this->stock_on_hand,
            'reference' => $reference !== '' ? $reference : null,
            'note' => $note,
            'created_by' => $by?->getKey(),
            'created_at' => now(),
        ]);
    }

    public function displayName(): string
    {
        return trim(($this->product?->name ?? '').' '.($this->name ?? ''));
    }

    public function unitPrice(): Money
    {
        return $this->price;
    }

    #[Scope]
    protected function sellable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('tracks_stock', false)
                ->orWhere('allow_backorder', true)
                ->orWhereRaw('stock_on_hand - stock_held > 0'));
    }

    /** Variants whose cache has drifted from their ledger. */
    #[Scope]
    protected function outOfBalance(Builder $query): void
    {
        $query->whereRaw(
            'stock_on_hand <> (SELECT COALESCE(SUM(quantity), 0) FROM inventory_movements '
            .'WHERE inventory_movements.product_variant_id = product_variants.id)'
        );
    }
}
