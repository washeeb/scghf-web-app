<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Contact enquiries and the departments they route to.
 */
class ContactPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'contact';
    }
}
