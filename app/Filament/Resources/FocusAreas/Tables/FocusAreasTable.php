<?php

declare(strict_types=1);

namespace App\Filament\Resources\FocusAreas\Tables;

use App\Filament\Support\ExportAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class FocusAreasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            /*
             * Eager-loaded, because the description column reaches through to
             * the division. Without it that is one query per row — invisible on
             * a screen with four areas on it and a real cost on one with forty,
             * and the tests catch it only because lazy loading is disabled there.
             */
            ->modifyQueryUsing(fn ($query) => $query->with('division'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Area'))
                    ->searchable()
                    ->description(fn ($record): ?string => $record->division?->name),

                TextColumn::make('projects_count')
                    ->label(__('Projects'))
                    ->counts('projects')
                    ->alignEnd(),

                IconColumn::make('is_active')->label(__('Shown'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Shown on the site')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('areas of work'), [
                    'Area' => 'name',
                    'Division' => fn ($record) => $record->division?->name,
                    'Shown' => 'is_active',
                ], ['division']),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
