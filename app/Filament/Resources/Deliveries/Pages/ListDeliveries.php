<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deliveries\Pages;

use App\Filament\Resources\Deliveries\DeliveryResource;
use Filament\Resources\Pages\ListRecords;

class ListDeliveries extends ListRecords
{
    protected static string $resource = DeliveryResource::class;

    public function getSubheading(): ?string
    {
        return __('Hand an order to a courier from the order itself: Orders → the order → "Assign a courier". Couriers confirm each step from /courier on their phone.');
    }
}
