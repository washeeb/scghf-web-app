<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Peer-to-peer supporter pages. Behind a feature flag that is off.
 */
class FundraiserPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'fundraisers';
    }
}
