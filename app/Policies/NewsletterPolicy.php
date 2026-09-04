<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Newsletters, campaigns, recipients and subscribers.
 */
class NewsletterPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'newsletter';
    }
}
