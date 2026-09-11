<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductCategories\Tables;

use App\Models\ProductCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category'))
                    ->searchable()
                    ->description(fn (ProductCategory $record): ?string => $record->parent
                        ? __('inside :parent', ['parent' => $record->parent->name])
                        : null),

                TextColumn::make('products_count')
                    ->label(__('Products'))
                    ->counts('products')
                    ->alignEnd(),

                TextColumn::make('policy_key')
                    ->label(__('Kind of goods'))
                    ->state(fn (ProductCategory $record): string => $record->isApproved()
                        ? __('Approved')
                        : __('Outside the agreed list'))
                    ->color(fn (ProductCategory $record): string => $record->isApproved() ? 'gray' : 'warning')
                    ->toggleable(),

                IconColumn::make('is_active')->label(__('Shown'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Shown in the shop')),
                Filter::make('outside_policy')
                    ->label(__('Outside the agreed list'))
                    ->query(fn (Builder $query) => $query->outsidePolicy()),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
