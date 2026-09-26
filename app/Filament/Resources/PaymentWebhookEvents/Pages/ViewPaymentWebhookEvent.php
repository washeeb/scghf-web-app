<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentWebhookEvents\Pages;

use App\Filament\Resources\PaymentWebhookEvents\PaymentWebhookEventResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentWebhookEvent extends ViewRecord
{
    protected static string $resource = PaymentWebhookEventResource::class;
}
