<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Email delivery records.
 *
 * Read-only. A log somebody can edit is not a log, and this one is what
 * answers "did the donor ever receive their receipt?"
 */
class EmailLogPolicy extends ReadOnlyPolicy
{
    protected function prefix(): string
    {
        return 'logs.email';
    }
}
