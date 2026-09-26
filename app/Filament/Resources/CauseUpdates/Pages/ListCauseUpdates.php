<?php

namespace App\Filament\Resources\CauseUpdates\Pages;

use App\Filament\Resources\CauseUpdates\CauseUpdateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCauseUpdates extends ListRecords
{
    protected static string $resource = CauseUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
