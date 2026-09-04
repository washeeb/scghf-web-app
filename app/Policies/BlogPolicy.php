<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Posts, categories, tags and comments. Publishing is separate from writing.
 */
class BlogPolicy extends PublishablePolicy
{
    protected function prefix(): string
    {
        return 'blog';
    }
}
