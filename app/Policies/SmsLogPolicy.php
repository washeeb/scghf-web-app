<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * SMS delivery records, with segment counts and estimated cost.
 *
 * Read-only, and also the only source for reconciling a provider's invoice.
 */
class SmsLogPolicy extends ReadOnlyPolicy
{
    protected function prefix(): string
    {
        return 'logs.sms';
    }
}
