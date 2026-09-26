<?php

declare(strict_types=1);

namespace App\Filament\Resources\BlogCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class BlogCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category'))
                    ->searchable()
                    ->description(fn ($record): string => (string) $record->slug),

                TextColumn::make('posts_count')
                    ->label(__('Posts'))
                    ->counts('posts')
                    ->alignEnd(),

                IconColumn::make('is_published')
                    ->label(__('Shown'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_published')->label(__('Shown on the site')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
