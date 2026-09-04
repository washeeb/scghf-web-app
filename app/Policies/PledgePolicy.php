<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Promises to give. Not money — see App\Models\Pledge.
 */
class PledgePolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'pledges';
    }
}
