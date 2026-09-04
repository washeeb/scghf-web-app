<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * URL redirects, including the 404-to-redirect workflow.
 */
class RedirectPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'redirects';
    }
}
