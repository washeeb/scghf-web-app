<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One line on an order — a SNAPSHOT, never a join.
 *
 * The product name, SKU and unit price are copied here at the moment of order
 * and never read from the live product again. A price change must not
 * retroactively alter what a customer paid: that is an accounting error and a
 * trust problem at once, and it is the reason `product_variant_id` is nullable
 * with ON DELETE SET NULL. A product discontinued in two years takes nothing
 * with it; this row still prints the invoice correctly.
 *
 * @property Money|null $unit_price
 * @property Money|null $line_total
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'product_variant_id', 'product_id',
        'product_name', 'variant_name', 'sku',
        'quantity', 'unit_price', 'line_total', 'currency', 'weight_grams',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'GHS'];

    protected function casts(): array
    {
        return [
            'unit_price' => MoneyCast::class.':unit_price_minor,currency',
            'line_total' => MoneyCast::class.':line_total_minor,currency',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->quantity < 1) {
                throw new RuntimeException('An order line must be for at least one item.');
            }

            /*
             * The line total is derived, not supplied. Accepting one from the
             * caller would let a request set its own price — quantity times
             * unit price is the only figure this row is allowed to hold.
             */
            $item->line_total_minor = $item->unit_price_minor * $item->quantity;
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** How the line reads on an invoice. */
    public function label(): string
    {
        return trim($this->product_name.' '.($this->variant_name ?? ''));
    }

    public function lineTotal(): Money
    {
        return $this->line_total;
    }

    /**
     * Build a line from a variant, freezing everything that matters.
     *
     * The one place an order line is created from a live product, so there is
     * one definition of what gets snapshotted.
     */
    public static function fromVariant(Order $order, ProductVariant $variant, int $quantity, ?Money $unitPrice = null): self
    {
        return self::create([
            'order_id' => $order->getKey(),
            'product_variant_id' => $variant->getKey(),
            'product_id' => $variant->product_id,
            'product_name' => (string) $variant->product?->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'quantity' => $quantity,
            'unit_price' => $unitPrice ?? $variant->price,
            'currency' => $variant->currency,
            'weight_grams' => $variant->weight_grams,
        ]);
    }
}
