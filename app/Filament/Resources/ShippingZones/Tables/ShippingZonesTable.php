<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShippingZones\Tables;

use App\Models\ShippingRate;
use App\Models\ShippingZone;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The zones, and the one thing worth seeing at a glance: which regions no
 * active zone serves. A customer in Savannah whose region is missing from
 * the checkout does not complain; they leave.
 */
class ShippingZonesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('rates'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->heading(fn (): ?string => ($unserved = ShippingZone::unservedRegions()) === []
                ? null
                : __('Not served by any active zone: :regions', ['regions' => implode(', ', $unserved)]))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Zone'))
                    ->description(fn (ShippingZone $record): ?string => $record->is_pickup
                        ? __('Collection')
                        : implode(', ', (array) $record->regions)),

                TextColumn::make('rates')
                    ->label(__('Rates'))
                    ->state(fn (ShippingZone $record): string => $record->is_pickup
                        ? __('free')
                        : ($record->rates->where('is_active', true)
                            ->map(fn (ShippingRate $rate): string => $rate->name.' '.$rate->price->format())
                            ->implode(' · ') ?: __('none yet')))
                    ->color(fn (ShippingZone $record): string => ! $record->is_pickup && $record->rates->where('is_active', true)->isEmpty() ? 'warning' : 'gray'),

                IconColumn::make('is_active')->label(__('Offered'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
