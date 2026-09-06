<?php

namespace App\Filament\Resources\CauseUpdates\Pages;

use App\Filament\Resources\CauseUpdates\CauseUpdateResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCauseUpdate extends EditRecord
{
    protected static string $resource = CauseUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
