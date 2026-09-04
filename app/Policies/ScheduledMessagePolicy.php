<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The outbox. Cancelling a queued message can stop a campaign mid-send.
 */
class ScheduledMessagePolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'messages';
    }
}
