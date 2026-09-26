<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsBroadcasts\Pages;

use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSmsBroadcast extends CreateRecord
{
    protected static string $resource = SmsBroadcastResource::class;
}
