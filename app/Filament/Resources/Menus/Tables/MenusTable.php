<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Tables;

use App\Models\Menu;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The menus.
 *
 * A short, fixed list — header, the footer columns, the legal strip. There is
 * no "create" action and no bulk delete: the layout asks for each menu by key,
 * so a menu that does not exist is a part of the page that renders empty, and
 * one nobody asked for is a menu nothing draws. New menus arrive with the
 * template that needs them.
 */
class MenusTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Menu'))
                    ->searchable()
                    ->description(fn (Menu $record): string => (string) $record->description),

                TextColumn::make('key')
                    ->label(__('Key'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('items_count')
                    ->label(__('Items'))
                    ->counts('items')
                    ->alignEnd(),

                TextColumn::make('max_depth')
                    ->label(__('Levels'))
                    ->formatStateUsing(fn (int $state): string => $state === 0
                        ? __('Flat')
                        : trans_choice('1 level of dropdown|:count levels of dropdown', $state, ['count' => $state]))
                    ->toggleable(),

                IconColumn::make('is_locked')
                    ->label(__('Used by the layout'))
                    ->boolean()
                    ->tooltip(__('A locked menu is one the site renders by key. It can be edited but not renamed or removed.')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (Menu $record): bool => ! $record->is_locked),
            ]);
    }
}
