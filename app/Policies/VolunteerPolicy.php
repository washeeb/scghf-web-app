<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Volunteers, applications, opportunities, hours and safeguarding checks.
 */
class VolunteerPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'volunteers';
    }
}
