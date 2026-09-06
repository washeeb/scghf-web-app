<?php

declare(strict_types=1);

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectStatus;
use App\Filament\Support\ExportAction;
use App\Models\Project;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Project'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (Project $record): ?string => $record->primaryLocation()?->region),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (ProjectStatus $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('starts_on')->label(__('Started'))->date('M Y')->sortable()->placeholder('—'),

                TextColumn::make('causes_count')
                    ->label(__('Appeals'))
                    ->counts('causes')
                    ->alignEnd()
                    ->toggleable(),

                IconColumn::make('is_published')->label(__('Shown'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(ProjectStatus::options()),
                SelectFilter::make('focusAreas')->label(__('Area of work'))->relationship('focusAreas', 'name'),
                TernaryFilter::make('is_published')->label(__('Shown on the site')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('projects'), [
                    'Project' => 'title',
                    'Status' => 'status',
                    'Started' => 'starts_on',
                    'Ends' => 'ends_on',
                    'Budget' => fn ($record) => $record->budget?->format(),
                    'Shown' => 'is_published',
                ]),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
