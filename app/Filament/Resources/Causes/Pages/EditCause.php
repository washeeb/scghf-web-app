<?php

namespace App\Filament\Resources\Causes\Pages;

use App\Filament\Resources\Causes\CauseResource;
use App\Models\Cause;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCause extends EditRecord
{
    protected static string $resource = CauseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * The live thermometer, for a projector at an event. Only a
             * published appeal has one — the page 404s otherwise — so the
             * button is hidden until the appeal is live.
             */
            Action::make('screen')
                ->label(__('Live screen'))
                ->icon('heroicon-o-presentation-chart-bar')
                ->url(fn (Cause $record): string => route('screen.show', $record))
                ->openUrlInNewTab()
                ->visible(fn (Cause $record): bool => $record->isLive()),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
