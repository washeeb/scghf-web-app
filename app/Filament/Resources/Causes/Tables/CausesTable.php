<?php

declare(strict_types=1);

namespace App\Filament\Resources\Causes\Tables;

use App\Enums\CauseStatus;
use App\Filament\Support\ExportAction;
use App\Models\Cause;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The appeals.
 *
 * ── The progress column is the one that gets read ───────────────────────────
 *
 * An appeal at 8% with three days left needs a decision this week; one at 140%
 * is news worth telling. Neither is visible in a list of titles and statuses,
 * which is what a fundraising screen usually is.
 *
 * ── "Closed but still switched on" is a filter ──────────────────────────────
 *
 * The same shape as the stale announcement: an appeal past its closing date but
 * still marked active is one the site may still be taking money for, which is
 * the worst version of forgetting to update something.
 */
class CausesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            // The title's description reads the project; strict Eloquent
            // refuses that lazy load once the list has more than one row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Appeal'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (Cause $record): ?string => $record->project?->title),

                TextColumn::make('raised')
                    ->label(__('Raised'))
                    ->alignEnd()
                    ->state(fn (Cause $record): string => $record->raisedAmount()->format())
                    ->description(fn (Cause $record): ?string => $record->progressPercent() === null
                        ? __('no target')
                        : __(':percent% of :goal', [
                            'percent' => $record->progressPercent(),
                            'goal' => $record->goal?->format(),
                        ]))
                    ->color(fn (Cause $record): string => match (true) {
                        $record->progressPercent() === null => 'gray',
                        $record->progressPercent() >= 100 => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('donation_count')->label(__('Gifts'))->alignEnd()->toggleable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (CauseStatus $state): string => $state->label())
                    ->description(fn (Cause $record): ?string => $record->ends_on?->toFormattedDateString()),

                IconColumn::make('is_published')->label(__('Shown'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(CauseStatus::options()),
                TernaryFilter::make('is_published')->label(__('Shown on the site')),

                Filter::make('accepting')
                    ->label(__('Taking donations now'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->accepting()),

                Filter::make('closed_but_active')
                    ->label(__('Past its closing date but still active'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', CauseStatus::Active->value)
                        ->whereNotNull('ends_on')
                        ->whereDate('ends_on', '<', now())),

                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('appeals'), [
                    'Appeal' => 'title',
                    'Project' => fn ($record) => $record->project?->title,
                    'Goal' => fn ($record) => $record->goal?->format(),
                    'Raised' => fn ($record) => $record->raisedAmount()->format(),
                    'Gifts' => 'donation_count',
                    'Status' => 'status',
                    'Closes' => 'ends_on',
                    'Shown' => 'is_published',
                ], ['project']),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
