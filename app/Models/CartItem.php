<?php

declare(strict_types=1);

namespace App\Models;

use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line in a basket: a variant and a quantity, and nothing else.
 *
 * No price column, deliberately. See `Cart` — a basket is a wish, and the price
 * is whatever it is when the customer actually checks out.
 */
class CartItem extends Model
{
    use HasFactory;

    protected $fillable = ['cart_id', 'product_variant_id', 'quantity'];

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** The live unit price for this quantity and this customer. */
    public function unitPrice(): Money
    {
        return $this->variant?->priceFor($this->quantity, $this->cart?->user_id !== null) ?? Money::zero();
    }

    /** The live price, times the quantity. */
    public function lineTotal(): Money
    {
        return $this->variant === null ? Money::zero() : $this->unitPrice()->times($this->quantity);
    }

    /**
     * Whether this line can still be bought.
     *
     * Checks the product is live as well as the variant sellable — a product
     * pulled for regulatory review must not remain buyable from a basket
     * somebody filled the week before.
     */
    public function isAvailable(): bool
    {
        $variant = $this->variant;

        if ($variant === null) {
            return false;
        }

        return $variant->isSellable($this->quantity)
            && ($variant->product?->isLive() ?? false);
    }
}
