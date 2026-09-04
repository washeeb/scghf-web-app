<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Projects, their updates, milestones and locations.
 */
class ProjectPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'projects';
    }
}
