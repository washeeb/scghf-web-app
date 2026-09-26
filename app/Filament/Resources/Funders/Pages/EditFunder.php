<?php

declare(strict_types=1);

namespace App\Filament\Resources\Funders\Pages;

use App\Filament\Resources\Funders\FunderResource;
use Filament\Resources\Pages\EditRecord;

class EditFunder extends EditRecord
{
    protected static string $resource = FunderResource::class;

    protected function getHeaderActions(): array
    {
        // No delete: a funder with grants is history, and `restrictOnDelete`
        // would refuse anyway.
        return [];
    }
}
