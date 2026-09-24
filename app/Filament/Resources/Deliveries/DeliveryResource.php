<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deliveries;

use App\Filament\Resources\Deliveries\Pages\ListDeliveries;
use App\Filament\Resources\Deliveries\Tables\DeliveriesTable;
use App\Models\Delivery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every order in a courier's hands, in one list.
 *
 * Assigning happens on the order page ("Assign a courier"); this is the
 * office's overview — what is out, with whom, since when, and what could
 * not be delivered — with a way to reassign or cancel from the row.
 */
class DeliveryResource extends Resource
{
    protected static ?string $model = Delivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?int $navigationSort = 31;

    protected static ?string $modelLabel = 'Delivery';

    protected static ?string $pluralModelLabel = 'Deliveries';

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['order.reference', 'order.delivery_name', 'recipient_name'];
    }

    /** @return array<string, string|null> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        if (! $record instanceof Delivery) {
            return [];
        }

        return ['Order' => $record->order?->reference, 'Status' => $record->label()];
    }

    public static function table(Table $table): Table
    {
        return DeliveriesTable::configure($table);
    }

    /** Deliveries that could not be made and are waiting on the office. */
    public static function getNavigationBadge(): ?string
    {
        $failed = Delivery::query()->where('status', Delivery::STATUS_FAILED)->count();

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveries::route('/'),
        ];
    }
}
