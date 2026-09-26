<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Products, variants, images and categories.
 */
class ProductPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'products';
    }
}
