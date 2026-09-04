<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Banners, announcement bars and popups.
 */
class AnnouncementPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'announcements';
    }
}
