<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The suppression list. Releasing an address is its own permission.
 */
class SuppressionPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'suppressions';
    }
}
