<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Pages;

use App\Filament\Resources\Menus\MenuResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No create action: the set of menus is decided by the templates that render
 * them, not by an editor. A menu nobody draws is a menu nothing shows.
 */
class ListMenus extends ListRecords
{
    protected static string $resource = MenuResource::class;
}
