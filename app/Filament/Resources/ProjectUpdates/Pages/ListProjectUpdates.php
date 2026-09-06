<?php

namespace App\Filament\Resources\ProjectUpdates\Pages;

use App\Filament\Resources\ProjectUpdates\ProjectUpdateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectUpdates extends ListRecords
{
    protected static string $resource = ProjectUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
