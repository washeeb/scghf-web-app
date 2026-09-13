<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsBroadcasts\Pages;

use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSmsBroadcasts extends ListRecords
{
    protected static string $resource = SmsBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
