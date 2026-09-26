<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Delivery zones and rates.
 */
class ShippingPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'shipping';
    }
}
