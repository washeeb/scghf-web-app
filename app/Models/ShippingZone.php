<?php

declare(strict_types=1);

namespace App\Models;

use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A group of Ghanaian regions with the same delivery pricing.
 *
 * Pickup is a zone with a zero rate rather than a branch in the checkout, so
 * there is one code path from basket to order whether the customer collects or
 * has it delivered.
 */
class ShippingZone extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * Ghana's sixteen regions.
     *
     * Held here rather than in config because it is a fact about the country,
     * not a policy the foundation sets — and a dropdown that quietly omits
     * Savannah or Oti tells a customer there that the shop does not serve them.
     *
     * @var array<int, string>
     */
    public const REGIONS = [
        'Ahafo', 'Ashanti', 'Bono', 'Bono East', 'Central', 'Eastern',
        'Greater Accra', 'North East', 'Northern', 'Oti', 'Savannah',
        'Upper East', 'Upper West', 'Volta', 'Western', 'Western North',
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'regions', 'is_pickup',
        'pickup_address', 'pickup_hours', 'pickup_phone', 'sort_order', 'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_pickup' => false,
        'sort_order' => 0,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'regions' => 'array',
            'is_pickup' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $zone): void {
            if (blank($zone->slug)) {
                $zone->slug = Str::slug($zone->name);
            }

            $zone->slug = Str::slug($zone->slug);
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<ShippingRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class)->orderBy('sort_order');
    }

    public function covers(string $region): bool
    {
        return in_array($region, (array) $this->regions, true);
    }

    /** The zone serving a region, or null if the shop does not deliver there. */
    public static function forRegion(string $region): ?self
    {
        return static::query()->active()->get()
            ->first(fn (self $zone): bool => ! $zone->is_pickup && $zone->covers($region));
    }

    /**
     * The cheapest rate that applies to a basket.
     *
     * Weight banding is checked, so an order too heavy for the standard rate
     * falls through to whichever one covers it rather than being priced as if
     * it were light.
     */
    public function rateFor(Money $subtotal, int $weightGrams = 0): ?ShippingRate
    {
        return $this->rates
            ->where('is_active', true)
            ->filter(fn (ShippingRate $rate): bool => $rate->appliesTo($weightGrams))
            ->sortBy(fn (ShippingRate $rate): int => $rate->priceFor($subtotal)->toMinor())
            ->first();
    }

    /** Regions no active zone serves — a gap somebody should know about. */
    public static function unservedRegions(): array
    {
        $covered = static::query()->active()->where('is_pickup', false)->get()
            ->flatMap(fn (self $zone): array => (array) $zone->regions)
            ->unique()
            ->all();

        return array_values(array_diff(self::REGIONS, $covered));
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }
}
