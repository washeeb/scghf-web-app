<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentWebhookEvents\Pages;

use App\Filament\Resources\PaymentWebhookEvents\PaymentWebhookEventResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentWebhookEvents extends ListRecords
{
    protected static string $resource = PaymentWebhookEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
