<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Captured application errors, grouped by fingerprint.
 */
class ErrorReportPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'error_reports';
    }
}
