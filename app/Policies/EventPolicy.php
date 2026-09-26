<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Events, registrations and ticket types.
 */
class EventPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'events';
    }
}
