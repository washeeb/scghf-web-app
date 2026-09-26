<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Daily aggregate visitor counts.
 *
 * Read-only, and holding no per-visitor row by construction. There is nothing
 * here about a person to authorise access to.
 */
class VisitorStatPolicy extends ReadOnlyPolicy
{
    protected function prefix(): string
    {
        return 'visitor_stats';
    }
}
