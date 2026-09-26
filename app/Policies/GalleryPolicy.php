<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Galleries and the images in them.
 */
class GalleryPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'galleries';
    }
}
