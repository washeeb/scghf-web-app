<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * spatie/laravel-activitylog's record of model changes.
 *
 * Distinct from the audit trail, which records actions. Both read-only.
 */
class ActivityLogPolicy extends ReadOnlyPolicy
{
    protected function prefix(): string
    {
        return 'activity_log';
    }
}
