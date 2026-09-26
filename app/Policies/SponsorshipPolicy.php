<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Child and student sponsorship. Its own permissions rather than riding on `beneficiaries`.
 */
class SponsorshipPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'sponsorships';
    }
}
