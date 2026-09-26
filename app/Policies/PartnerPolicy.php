<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Partner organisations and their logos.
 */
class PartnerPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'partners';
    }
}
