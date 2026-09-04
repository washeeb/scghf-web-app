<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Feature flag overrides. `donations` is locked against override in the model.
 */
class FeatureFlagPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'feature_flags';
    }
}
