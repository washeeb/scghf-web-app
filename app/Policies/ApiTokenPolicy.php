<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * API tokens. Hashed, expiring, deny-by-default abilities.
 */
class ApiTokenPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'api_tokens';
    }
}
