<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerOpportunities\Pages;

use App\Filament\Resources\VolunteerOpportunities\VolunteerOpportunityResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditVolunteerOpportunity extends EditRecord
{
    protected static string $resource = VolunteerOpportunityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
