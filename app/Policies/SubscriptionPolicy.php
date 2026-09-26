<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Recurring giving arrangements and their charge attempts.
 */
class SubscriptionPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'subscriptions';
    }
}
