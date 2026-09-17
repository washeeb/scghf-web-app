<?php

declare(strict_types=1);

namespace App\Filament\Resources\Offices\Tables;

use App\Models\Office;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OfficesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('Office'))->searchable()->description(fn (Office $record): ?string => $record->city),
                TextColumn::make('region')->label(__('Region'))->placeholder('—')->toggleable(),
                TextColumn::make('phone')->label(__('Phone'))->placeholder('—'),
                TextColumn::make('whatsapp')->label(__('WhatsApp'))->placeholder('—')->toggleable(),
                IconColumn::make('is_primary')->label(__('Main'))->boolean(),
                IconColumn::make('is_active')->label(__('Shown'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
