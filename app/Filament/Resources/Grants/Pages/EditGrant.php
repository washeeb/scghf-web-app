<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\Pages;

use App\Filament\Resources\Grants\GrantResource;
use Filament\Resources\Pages\EditRecord;

class EditGrant extends EditRecord
{
    protected static string $resource = GrantResource::class;

    protected function getHeaderActions(): array
    {
        // No delete: a declined or closed grant is the record of what was
        // asked and answered, and the next application starts from it.
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
