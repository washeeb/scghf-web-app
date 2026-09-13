<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\Concerns\StoresDownloadFile;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use StoresDownloadFile;

    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        $this->storeDownloadFile();
    }
}
