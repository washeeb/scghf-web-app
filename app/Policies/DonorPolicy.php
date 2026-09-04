<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Donor records. Contact details, giving history, merge.
 */
class DonorPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'donors';
    }
}
