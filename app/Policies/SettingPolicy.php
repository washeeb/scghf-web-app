<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * The CMS settings layer and the theme tokens.
 */
class SettingPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'settings';
    }
}
