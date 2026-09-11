<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerOpportunities\Pages;

use App\Filament\Resources\VolunteerOpportunities\VolunteerOpportunityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVolunteerOpportunities extends ListRecords
{
    protected static string $resource = VolunteerOpportunityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
