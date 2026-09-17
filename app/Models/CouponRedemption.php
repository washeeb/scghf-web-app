<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a coupon.
 *
 * Its own row rather than a counter alone, so "who used this and when" is
 * answerable and a per-customer limit can actually be enforced. The counter on
 * the coupon is the cache of these.
 *
 * @property Money|null $discount
 */
class CouponRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'coupon_id', 'order_id', 'user_id', 'customer_email', 'discount', 'currency',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['currency' => 'GHS'];

    protected function casts(): array
    {
        return ['discount' => MoneyCast::class.':discount_minor,currency'];
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
