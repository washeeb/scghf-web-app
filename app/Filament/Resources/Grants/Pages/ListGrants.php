<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\Pages;

use App\Filament\Resources\Grants\GrantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGrants extends ListRecords
{
    protected static string $resource = GrantResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New grant'))];
    }
}
