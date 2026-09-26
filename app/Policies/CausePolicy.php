<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Causes and campaign updates — where donations are destined.
 */
class CausePolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'causes';
    }
}
