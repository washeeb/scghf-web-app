<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerOpportunities\Pages;

use App\Filament\Resources\VolunteerOpportunities\VolunteerOpportunityResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVolunteerOpportunity extends CreateRecord
{
    protected static string $resource = VolunteerOpportunityResource::class;
}
