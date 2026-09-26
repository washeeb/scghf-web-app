<?php

namespace App\Filament\Resources\ImpactMetrics\Pages;

use App\Filament\Resources\ImpactMetrics\ImpactMetricResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListImpactMetrics extends ListRecords
{
    protected static string $resource = ImpactMetricResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
