<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImpactMetrics\Tables;

use App\Filament\Support\ExportAction;
use App\Models\ImpactMetric;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * The measures, with what they currently say.
 *
 * ── Two columns, and the difference between them matters ────────────────────
 *
 * "Total" is the real figure, shown to staff who are entitled to it. "Published"
 * is what a visitor would see — and for a metric counting people it may be
 * withheld. Showing only one of them would leave somebody unable to tell
 * whether a number is missing from the public page because it is zero or
 * because disclosure control held it back.
 */
class ImpactMetricsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['project', 'values']))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Measure'))
                    ->searchable()
                    ->description(fn (ImpactMetric $record): ?string => $record->project?->title),

                TextColumn::make('total')
                    ->label(__('Total'))
                    ->alignEnd()
                    ->state(fn (ImpactMetric $record): string => $record->format($record->total())),

                TextColumn::make('published')
                    ->label(__('Shown publicly'))
                    ->alignEnd()
                    ->state(fn (ImpactMetric $record): string => $record->format($record->publishedTotal()))
                    ->color(fn (ImpactMetric $record): string => $record->publishedTotal() === null ? 'warning' : 'gray')
                    ->description(fn (ImpactMetric $record): ?string => $record->publishedTotal() === null
                        ? __('withheld — too few people to publish safely')
                        : null),

                TextColumn::make('values_count')
                    ->label(__('Readings'))
                    ->counts('values')
                    ->alignEnd()
                    ->toggleable(),

                IconColumn::make('counts_people')
                    ->label(__('People'))
                    ->boolean()
                    ->toggleable(),

                IconColumn::make('is_public')->label(__('Published'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_public')->label(__('On the impact page')),
                TernaryFilter::make('counts_people')->label(__('Counts people')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('impact measures'), [
                    'Measure' => 'name',
                    'Project' => fn ($record) => $record->project?->title,
                    'Unit' => 'unit',
                    'Counts people' => 'counts_people',
                    // ⚠ The PUBLISHED figure, not the raw total. An export
                    // leaves the application's protections behind and lands in
                    // somebody's Downloads folder; disclosure control has to
                    // travel with it.
                    'Published total' => fn ($record) => $record->format($record->publishedTotal()),
                    'Shown publicly' => 'is_public',
                ], ['project', 'values']),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
