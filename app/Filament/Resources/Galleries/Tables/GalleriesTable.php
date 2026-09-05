<?php

declare(strict_types=1);

namespace App\Filament\Resources\Galleries\Tables;

use App\Filament\Support\ExportAction;
use App\Models\Gallery;
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

/**
 * The albums.
 *
 * Newest first by the date the photographs were taken, not by when somebody
 * typed them in — a foundation uploading last year's outreach in a quiet week
 * should not have it lead the list.
 */
class GalleriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('taken_on', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Album'))
                    ->searchable()
                    ->description(fn (Gallery $record): ?string => $record->location),

                TextColumn::make('items_count')
                    ->label(__('Photographs'))
                    ->counts('items')
                    ->alignEnd()
                    // An empty published album is a link to a blank page.
                    ->color(fn (Gallery $record, int $state): string => $state === 0 && $record->is_published ? 'danger' : 'gray'),

                TextColumn::make('taken_on')
                    ->label(__('Taken'))
                    ->date('j M Y')
                    ->sortable()
                    ->placeholder('—'),

                IconColumn::make('has_consent')
                    ->label(__('Consent'))
                    ->boolean(),

                IconColumn::make('is_published')
                    ->label(__('Shown'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_published')->label(__('Shown on the site')),

                Filter::make('awaiting_consent')
                    ->label(__('Waiting on consent'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('has_consent', false)),

                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('galleries'), [
                    'Album' => 'title',
                    'Where' => 'location',
                    'Taken' => 'taken_on',
                    'Consent recorded' => 'has_consent',
                    'Shown' => 'is_published',
                ]),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
