<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Downloadable documents — policies, reports, forms.
 */
class DocumentPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'documents';
    }
}
