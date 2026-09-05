<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\RecordsAuthor;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A discount code.
 *
 * Validity is evaluated from the dates and the usage counts on every call,
 * never from a stored status — the same rule as a GRA approval and a consent
 * record. Nothing about what a customer is charged should depend on a cron job
 * having run last night.
 *
 * A percentage coupon can carry a ceiling, because "20% off" applied to an
 * unusually large order is a discount nobody signed off.
 */
class Coupon extends Model
{
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    public const TYPE_FREE_SHIPPING = 'free_shipping';

    protected $fillable = [
        'code', 'description', 'discount_type', 'discount_value',
        'minimum_spend', 'maximum_discount', 'usage_limit',
        'usage_limit_per_customer', 'starts_at', 'expires_at', 'is_active', 'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'discount_type' => self::TYPE_PERCENTAGE,
        'times_used' => 0,
        'is_active' => true,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'minimum_spend' => MoneyCast::class.':minimum_spend_minor',
            'maximum_discount' => MoneyCast::class.':maximum_discount_minor',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $coupon): void {
            // Uppercased so FRIENDS10 and friends10 are one coupon, and a
            // customer typing either gets the discount they were promised.
            $coupon->code = mb_strtoupper(trim((string) $coupon->code));

            if ($coupon->discount_type === self::TYPE_PERCENTAGE && $coupon->discount_value > 10000) {
                throw new RuntimeException('A percentage discount cannot exceed 100%.');
            }

            if ($coupon->expires_at !== null && $coupon->starts_at !== null
                && $coupon->expires_at->lte($coupon->starts_at)) {
                throw new RuntimeException('A coupon cannot expire before it starts.');
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
        return 'code';
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * Why this coupon cannot be used, or null if it can.
     *
     * Returns a REASON rather than a boolean, because "that code is not valid"
     * with no explanation is how a customer abandons a basket over a coupon
     * that expired yesterday and one they had already used.
     */
    public function rejectionReason(Money $subtotal, ?string $customerEmail = null, ?Carbon $on = null): ?string
    {
        $on ??= now();

        if (! $this->is_active) {
            return 'This code is no longer available.';
        }

        if ($this->starts_at !== null && $this->starts_at->gt($on)) {
            return 'This code is not valid yet.';
        }

        if ($this->expires_at !== null && $this->expires_at->lt($on)) {
            return 'This code expired on '.$this->expires_at->format('j F Y').'.';
        }

        if ($this->usage_limit !== null && $this->times_used >= $this->usage_limit) {
            return 'This code has been fully redeemed.';
        }

        if ($this->minimum_spend !== null && $subtotal->lessThan($this->minimum_spend)) {
            return 'This code needs a basket of at least '.$this->minimum_spend->format().'.';
        }

        if ($this->usage_limit_per_customer !== null && $customerEmail !== null) {
            $used = $this->redemptions()->where('customer_email', mb_strtolower($customerEmail))->count();

            if ($used >= $this->usage_limit_per_customer) {
                return 'You have already used this code.';
            }
        }

        return null;
    }

    public function isUsableBy(Money $subtotal, ?string $customerEmail = null): bool
    {
        return $this->rejectionReason($subtotal, $customerEmail) === null;
    }

    /**
     * What this coupon takes off a basket.
     *
     * Never more than the subtotal: a discount larger than the order would make
     * the total negative, and `orders.total_minor` is unsigned for the good
     * reason that a negative sale is not a thing.
     */
    public function discountFor(Money $subtotal, ?Money $shipping = null): Money
    {
        $shipping ??= Money::zero($subtotal->currency);

        $discount = match ($this->discount_type) {
            self::TYPE_FIXED => Money::ofMinor((int) $this->discount_value, $subtotal->currency),
            self::TYPE_FREE_SHIPPING => $shipping,
            // Basis points, so 1000 = 10% and no float enters the arithmetic.
            default => Money::ofMinor(
                intdiv($subtotal->toMinor() * (int) $this->discount_value, 10000),
                $subtotal->currency,
            ),
        };

        if ($this->maximum_discount !== null && $discount->greaterThan($this->maximum_discount)) {
            $discount = $this->maximum_discount;
        }

        $ceiling = $this->discount_type === self::TYPE_FREE_SHIPPING ? $shipping : $subtotal;

        return $discount->greaterThan($ceiling) ? $ceiling : $discount;
    }

    /** Record a use, incrementing the cache atomically. */
    public function redeem(Money $discount, ?Order $order = null, ?string $customerEmail = null): CouponRedemption
    {
        return DB::transaction(function () use ($discount, $order, $customerEmail): CouponRedemption {
            static::whereKey($this->getKey())->update([
                'times_used' => DB::raw('times_used + 1'),
                'updated_at' => now(),
            ]);

            return CouponRedemption::create([
                'coupon_id' => $this->getKey(),
                'order_id' => $order?->getKey(),
                'customer_email' => $customerEmail === null ? null : mb_strtolower($customerEmail),
                'discount' => $discount,
                'currency' => $discount->currency,
            ]);
        });
    }

    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->where(fn (Builder $q) => $q->whereNull('usage_limit')
                ->orWhereColumn('times_used', '<', 'usage_limit'));
    }
}
