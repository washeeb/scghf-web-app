<?php

declare(strict_types=1);

namespace App\Filament\Resources\Faqs\Tables;

use App\Models\Faq;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * The FAQ list, grouped by category and reorderable within it.
 *
 * The view count is a column rather than a hidden statistic. `faqs.view_count`
 * has been on the table since Phase 3 for exactly this: a question nobody opens
 * is one the page does not need, and a question opened constantly is usually a
 * sign that the answer belongs somewhere more prominent than an FAQ.
 */
class FaqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->defaultGroup('category.name')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('question')
                    ->label(__('Question'))
                    ->searchable()
                    ->wrap()
                    ->limit(120),

                TextColumn::make('view_count')
                    ->label(__('Opened'))
                    ->alignEnd()
                    ->sortable()
                    ->description(fn (Faq $record): ?string => $record->view_count === 0 && $record->is_published
                        ? __('never')
                        : null)
                    ->color(fn (Faq $record): string => $record->view_count === 0 && $record->is_published
                        ? 'warning'
                        : 'gray'),

                IconColumn::make('is_published')
                    ->label(__('Shown'))
                    ->boolean(),

                IconColumn::make('is_featured')
                    ->label(__('Featured'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('faq_category_id')
                    ->label(__('Category'))
                    ->relationship('category', 'name'),

                TernaryFilter::make('is_published')->label(__('Shown on the site')),
                TernaryFilter::make('is_featured')->label(__('Featured')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
