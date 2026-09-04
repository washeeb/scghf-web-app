<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Legal holds, the retention log and the GRA approval record.
 */
class CompliancePolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'compliance';
    }
}
