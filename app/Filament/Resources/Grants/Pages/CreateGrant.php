<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\Pages;

use App\Filament\Resources\Grants\GrantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGrant extends CreateRecord
{
    protected static string $resource = GrantResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
