<?php

namespace App\Filament\Resources\FocusAreas\Pages;

use App\Filament\Resources\FocusAreas\FocusAreaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFocusAreas extends ListRecords
{
    protected static string $resource = FocusAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
