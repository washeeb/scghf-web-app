<?php

declare(strict_types=1);

namespace App\Models;

use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A basket.
 *
 * Deliberately holds NO prices. A cart is a wish, not a contract: showing a
 * price captured three weeks ago at checkout would be the wrong number in the
 * direction that either annoys a customer or costs the foundation money. Prices
 * are read live from the variant right up to the moment the order is written,
 * and frozen onto the order line then.
 *
 * Carts expire and are swept. An abandoned basket holding an email address and
 * a coupon is personal data with no purpose left, and Act 843 says data is kept
 * only as long as it is needed for the purpose it was collected for.
 */
class Cart extends Model
{
    use HasFactory;
    use HasUlids;

    /** How long a basket survives without being touched. */
    public const LIFETIME_DAYS = 30;

    protected $fillable = [
        'user_id', 'session_token', 'coupon_id', 'customer_email', 'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $cart): void {
            $cart->session_token ??= Str::random(48);
            $cart->expires_at ??= now()->addDays(self::LIFETIME_DAYS);
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

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        // Chaperoned: a line prices itself by who owns the basket, and must
        // not lazy-load the basket to find out.
        return $this->hasMany(CartItem::class)->chaperone();
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Add to the basket, or increase what is already in it.
     *
     * A second "add" of the same mug increments the quantity rather than making
     * a second line nobody expects to see.
     */
    public function add(ProductVariant $variant, int $quantity = 1): CartItem
    {
        $item = $this->items()->firstOrNew(['product_variant_id' => $variant->getKey()]);

        $item->quantity = ($item->quantity ?? 0) + max(1, $quantity);
        $item->save();

        $this->touchExpiry();

        return $item;
    }

    public function remove(ProductVariant $variant): void
    {
        $this->items()->where('product_variant_id', $variant->getKey())->delete();

        $this->touchExpiry();
    }

    public function setQuantity(ProductVariant $variant, int $quantity): void
    {
        if ($quantity < 1) {
            $this->remove($variant);

            return;
        }

        $this->items()->where('product_variant_id', $variant->getKey())->update(['quantity' => $quantity]);

        $this->touchExpiry();
    }

    /** Prices read LIVE, never from the cart. */
    public function subtotal(): Money
    {
        return $this->items->reduce(
            fn (Money $carry, CartItem $item): Money => $carry->plus($item->lineTotal()),
            Money::zero(),
        );
    }

    public function totalWeightGrams(): int
    {
        return (int) $this->items->sum(
            fn (CartItem $item): int => (int) ($item->variant?->weight_grams ?? 0) * $item->quantity,
        );
    }

    public function itemCount(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /** Whether anything in the basket has to be carried somewhere. */
    public function requiresDelivery(): bool
    {
        return $this->items->contains(fn (CartItem $item): bool => $item->variant?->product?->requiresDelivery() ?? true);
    }

    /**
     * Lines that can no longer be fulfilled.
     *
     * Checked before checkout rather than at it: a customer discovering at the
     * payment page that a mug went out of stock a fortnight ago has had a worse
     * experience than being told on the basket page.
     *
     * @return array<int, string>
     */
    public function unavailableLines(): array
    {
        return $this->items
            ->reject(fn (CartItem $item): bool => $item->isAvailable())
            ->map(fn (CartItem $item): string => $item->variant?->displayName() ?? 'An item')
            ->values()
            ->all();
    }

    public function isCheckoutable(): bool
    {
        return ! $this->isEmpty() && $this->unavailableLines() === [];
    }

    /** Merge a guest basket into a signed-in one. */
    public function mergeFrom(self $other): void
    {
        foreach ($other->items as $item) {
            if ($item->variant !== null) {
                $this->add($item->variant, $item->quantity);
            }
        }

        $other->items()->delete();
        $other->delete();

        $this->load('items');
    }

    private function touchExpiry(): void
    {
        $this->forceFill(['expires_at' => now()->addDays(self::LIFETIME_DAYS)])->save();
        $this->load('items');
    }

    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }
}
