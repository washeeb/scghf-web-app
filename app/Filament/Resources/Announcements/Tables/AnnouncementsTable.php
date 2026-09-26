<?php

declare(strict_types=1);

namespace App\Filament\Resources\Announcements\Tables;

use App\Filament\Resources\Announcements\Schemas\AnnouncementForm;
use App\Filament\Support\ExportAction;
use App\Models\Announcement;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The notices.
 *
 * ── The state column answers the actual question ────────────────────────────
 *
 * "Switched on" and two dates do not tell anybody whether a notice is on the
 * site right now, which is the only thing they came here to find out. So the
 * column says Live, Scheduled, Finished or Off, and a finished notice that is
 * still switched on is flagged — that is the one that has been quietly
 * advertising a closed appeal.
 *
 * ── Impressions and clicks were being counted for nobody ────────────────────
 *
 * Both columns have been on the table since Phase 3 with nothing reading them.
 * A click-through rate is what tells the foundation whether the bar is worth
 * the strip of screen it costs on a phone.
 */
class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Notice'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (Announcement $record): string => AnnouncementForm::placements()[$record->placement]
                        ?? (string) $record->placement),

                TextColumn::make('state')
                    ->label(__('On the site'))
                    ->badge()
                    ->state(fn (Announcement $record): string => self::state($record))
                    ->color(fn (Announcement $record): string => match (self::state($record)) {
                        'Live' => 'success',
                        'Scheduled' => 'warning',
                        'Finished' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __($state))
                    ->description(fn (Announcement $record): ?string => $record->ends_at?->toFormattedDayDateString()),

                TextColumn::make('impressions')
                    ->label(__('Seen'))
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('clicks')
                    ->label(__('Clicked'))
                    ->alignEnd()
                    ->sortable()
                    ->description(fn (Announcement $record): ?string => $record->impressions === 0
                        ? null
                        : number_format($record->clickThroughRate(), 1).'%')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('placement')
                    ->label(__('Placement'))
                    ->options(AnnouncementForm::placements()),

                TernaryFilter::make('is_active')->label(__('Switched on')),

                Filter::make('live')
                    ->label(__('On the site now'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->live()),

                /*
                 * The one worth having. A notice still switched on after its
                 * end date is the stale-carol-service case, and it is invisible
                 * in a list sorted by anything else.
                 */
                Filter::make('expired')
                    ->label(__('Finished but still switched on'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '<=', now())),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('announcements'), [
                    'Notice' => 'title',
                    'Placement' => 'placement',
                    'From' => 'starts_at',
                    'Until' => 'ends_at',
                    'Switched on' => 'is_active',
                    'Seen' => 'impressions',
                    'Clicked' => 'clicks',
                ]),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    /** Live · Scheduled · Finished · Off. */
    private static function state(Announcement $record): string
    {
        if (! $record->is_active) {
            return 'Off';
        }

        if ($record->starts_at !== null && $record->starts_at->isFuture()) {
            return 'Scheduled';
        }

        if ($record->ends_at !== null && $record->ends_at->isPast()) {
            return 'Finished';
        }

        return 'Live';
    }
}
