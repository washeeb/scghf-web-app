<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What delivery costs in a zone.
 *
 * `free_above_minor` is nullable rather than a huge sentinel, because "this
 * rate has no free-delivery threshold" and "free delivery above GH₵ 1,000,000"
 * are different statements and only one of them is honest.
 *
 * @property Money|null $price
 * @property Money|null $free_above
 */
class ShippingRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_zone_id', 'name', 'price', 'currency', 'free_above',
        'min_weight_grams', 'max_weight_grams', 'estimated_days',
        'sort_order', 'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'GHS',
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price' => MoneyCast::class.':price_minor,currency',
            'free_above' => MoneyCast::class.':free_above_minor,currency',
        ];
    }

    /** @return BelongsTo<ShippingZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /** Whether this rate covers a basket of this weight. */
    public function appliesTo(int $weightGrams): bool
    {
        if ($this->min_weight_grams !== null && $weightGrams < $this->min_weight_grams) {
            return false;
        }

        return $this->max_weight_grams === null || $weightGrams <= $this->max_weight_grams;
    }

    /**
     * What to charge, honouring any free-delivery threshold.
     *
     * The threshold compares against the SUBTOTAL, not the total — otherwise
     * the delivery charge would count towards qualifying for free delivery,
     * which is circular and rewards nobody.
     */
    public function priceFor(Money $subtotal): Money
    {
        if ($this->free_above !== null && $subtotal->greaterThanOrEqual($this->free_above)) {
            return Money::zero($this->currency);
        }

        return $this->price;
    }

    public function isFreeFor(Money $subtotal): bool
    {
        return $this->priceFor($subtotal)->isZero();
    }

    /** How it reads at checkout: "Standard — GH₵ 25.00, 2–3 days". */
    public function label(Money $subtotal): string
    {
        $price = $this->priceFor($subtotal);

        return trim(sprintf(
            '%s — %s%s',
            $this->name,
            $price->isZero() ? 'free' : $price->format(),
            $this->estimated_days ? ', '.$this->estimated_days : '',
        ));
    }
}
