<?php

declare(strict_types=1);

namespace App\Filament\Resources\Funders\Pages;

use App\Filament\Resources\Funders\FunderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFunders extends ListRecords
{
    protected static string $resource = FunderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New funder'))];
    }
}
