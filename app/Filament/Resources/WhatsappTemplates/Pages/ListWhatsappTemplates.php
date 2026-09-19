<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsappTemplates\Pages;

use App\Filament\Resources\WhatsappTemplates\WhatsappTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListWhatsappTemplates extends ListRecords
{
    protected static string $resource = WhatsappTemplateResource::class;
}
