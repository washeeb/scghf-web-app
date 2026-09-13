<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppressions\Pages;

use App\Filament\Resources\Suppressions\SuppressionResource;
use Filament\Resources\Pages\ListRecords;

class ListSuppressions extends ListRecords
{
    protected static string $resource = SuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
