<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectUpdates\Tables;

use App\Models\ProjectUpdate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProjectUpdatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('project'))
            ->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Update'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (ProjectUpdate $record): ?string => $record->project?->title),

                TextColumn::make('published_at')
                    ->label(__('Published'))
                    ->dateTime('j M Y')
                    ->placeholder(__('Not yet'))
                    ->sortable(),

                IconColumn::make('is_published')->label(__('Shown'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('project_id')->label(__('Project'))->relationship('project', 'title'),
                TernaryFilter::make('is_published')->label(__('Shown on the site')),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
