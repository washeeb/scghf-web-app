<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Funders, grants, their obligations and their documents — Wave 2 (1.6).
 *
 * Internal records about where the larger money comes from. `grants.view`
 * reads; `grants.manage` writes. A funder's contact and the notes on a
 * grant are staff-only by construction: nothing public reads these tables.
 */
class GrantPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'grants';
    }
}
