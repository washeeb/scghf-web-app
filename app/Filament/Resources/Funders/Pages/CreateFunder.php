<?php

declare(strict_types=1);

namespace App\Filament\Resources\Funders\Pages;

use App\Filament\Resources\Funders\FunderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFunder extends CreateRecord
{
    protected static string $resource = FunderResource::class;
}
