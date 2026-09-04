<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The media library. Note `media.upload` rather than `media.create`, which the base resolves through its create/update fallback.
 */
class MediaPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'media';
    }
}
