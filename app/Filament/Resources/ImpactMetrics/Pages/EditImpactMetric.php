<?php

namespace App\Filament\Resources\ImpactMetrics\Pages;

use App\Filament\Resources\ImpactMetrics\ImpactMetricResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditImpactMetric extends EditRecord
{
    protected static string $resource = ImpactMetricResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
