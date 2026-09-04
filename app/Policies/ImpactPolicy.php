<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Impact metrics, their time series, and the anonymous analytics dataset.
 */
class ImpactPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'impact';
    }
}
