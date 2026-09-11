<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentWebhookEvents;

use App\Filament\Resources\PaymentWebhookEvents\Pages\ListPaymentWebhookEvents;
use App\Filament\Resources\PaymentWebhookEvents\Pages\ViewPaymentWebhookEvent;
use App\Filament\Resources\PaymentWebhookEvents\Schemas\PaymentWebhookEventInfolist;
use App\Filament\Resources\PaymentWebhookEvents\Tables\PaymentWebhookEventsTable;
use App\Models\PaymentWebhookEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every delivery the gateway made, verified or not, processed or not.
 * Evidence first: the raw body is stored before anything reads it.
 */
class PaymentWebhookEventResource extends Resource
{
    protected static ?string $model = PaymentWebhookEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'Webhook event';

    protected static ?string $pluralModelLabel = 'Webhook events';

    protected static ?string $recordTitleAttribute = 'event_id';

    public static function infolist(Schema $schema): Schema
    {
        return PaymentWebhookEventInfolist::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PaymentWebhookEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentWebhookEvents::route('/'),
            'view' => ViewPaymentWebhookEvent::route('/{record}'),
        ];
    }
}
