<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Product reviews. Moderated before publication, never after.
 */
class ReviewPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'reviews';
    }
}
