<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Prayer requests. Confidential by default; publication is gated in the model.
 */
class PrayerRequestPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'prayer_requests';
    }
}
