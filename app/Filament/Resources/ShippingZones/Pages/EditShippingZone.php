<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShippingZones\Pages;

use App\Filament\Resources\ShippingZones\ShippingZoneResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditShippingZone extends EditRecord
{
    protected static string $resource = ShippingZoneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
