<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The four divisions and their focus areas. Seeded and undeletable at the model layer.
 */
class DivisionPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'divisions';
    }
}
