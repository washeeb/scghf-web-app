<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Staff and donor accounts.
 */
class UserPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'users';
    }
}
