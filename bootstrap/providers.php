<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\CommunicationServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\PaymentServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    PaymentServiceProvider::class,
    CommunicationServiceProvider::class,
    AdminPanelProvider::class,
];
