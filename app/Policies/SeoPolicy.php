<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Per-entity SEO metadata.
 */
class SeoPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'seo';
    }
}
