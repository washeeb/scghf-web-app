<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Pages, their sections and their revisions. Drafting and publishing are separate permissions.
 */
class PagePolicy extends PublishablePolicy
{
    protected function prefix(): string
    {
        return 'pages';
    }
}
