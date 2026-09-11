<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerApplications\Pages;

use App\Filament\Resources\VolunteerApplications\VolunteerApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListVolunteerApplications extends ListRecords
{
    protected static string $resource = VolunteerApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
