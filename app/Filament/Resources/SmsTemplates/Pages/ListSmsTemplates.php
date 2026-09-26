<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsTemplates\Pages;

use App\Filament\Resources\SmsTemplates\SmsTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListSmsTemplates extends ListRecords
{
    protected static string $resource = SmsTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
