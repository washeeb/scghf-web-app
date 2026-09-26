<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Menus and their items. A single `menus.manage` — nobody has wanted to let somebody reorder a menu but not rename one.
 */
class MenuPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'menus';
    }
}
