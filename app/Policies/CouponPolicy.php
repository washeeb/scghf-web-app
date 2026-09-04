<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Discount coupons and their redemptions.
 */
class CouponPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'coupons';
    }
}
