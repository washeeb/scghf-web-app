<?php

declare(strict_types=1);

namespace App\Filament\Resources\Volunteers\Pages;

use App\Filament\Resources\Volunteers\VolunteerResource;
use App\Models\Volunteer;
use Filament\Resources\Pages\ListRecords;

class ListVolunteers extends ListRecords
{
    protected static string $resource = VolunteerResource::class;

    public function getSubheading(): ?string
    {
        $active = Volunteer::query()->where('status', Volunteer::STATUS_ACTIVE)->count();
        $hours = (int) Volunteer::query()->sum('total_hours');
        $lapsing = Volunteer::query()->clearanceLapsing()->count();

        return __(':active active · :hours verified hours in all · :lapsing clearance(s) lapsing within 60 days', [
            'active' => number_format($active),
            'hours' => number_format($hours),
            'lapsing' => number_format($lapsing),
        ]);
    }
}
