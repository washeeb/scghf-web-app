<?php

declare(strict_types=1);

namespace App\Filament\Resources\Redirects\Tables;

use App\Models\Redirect;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Redirects and the 404 log, in one list.
 *
 * ── Sorted by hits, not by date ─────────────────────────────────────────────
 *
 * The question this screen answers is "what is costing us visitors?", and the
 * answer is the path being hit most, whenever it was recorded. A list sorted by
 * date puts one scanner's probe from this morning above a broken link in a
 * printed flyer that four hundred people have followed.
 *
 * ── The default filter is the work queue ────────────────────────────────────
 *
 * Opening this screen shows captured 404s with no destination yet — the things
 * that need a decision. Working redirects are a click away and mostly need no
 * attention, which is the point of them.
 */
class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('hits', 'desc')
            ->columns([
                TextColumn::make('from_path')
                    ->label(__('Old address'))
                    ->searchable()
                    ->description(fn (Redirect $record): ?string => $record->last_referrer
                        ? __('from :url', ['url' => Str::limit($record->last_referrer, 60)])
                        : null),

                TextColumn::make('to_path')
                    ->label(__('Goes to'))
                    ->searchable()
                    ->placeholder(__('Not decided yet'))
                    ->color(fn (Redirect $record): string => $record->to_path === null ? 'warning' : 'gray')
                    ->wrap(),

                TextColumn::make('status_code')
                    ->label(__('Kind'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        301 => __('Permanent'),
                        302 => __('Temporary'),
                        410 => __('Removed'),
                        default => (string) $state,
                    })
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('hits')
                    ->label(__('Times hit'))
                    ->alignEnd()
                    ->sortable()
                    ->description(fn (Redirect $record): ?string => $record->last_hit_at?->diffForHumans()),

                IconColumn::make('is_active')
                    ->label(__('On'))
                    ->boolean(),
            ])
            ->filters([
                /*
                 * The work queue, and the default. `unresolved404s()` has been
                 * on the model since Phase 3 with nothing calling it.
                 */
                Filter::make('unresolved')
                    ->label(__('Recorded 404s needing a decision'))
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->unresolved404s()),

                SelectFilter::make('source')
                    ->label(__('Where it came from'))
                    ->options([
                        'manual' => __('Entered by hand'),
                        'auto_404' => __('Recorded automatically'),
                        'import' => __('Imported'),
                    ]),

                Filter::make('never_used')
                    ->label(__('Never used'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('is_active', true)->where('hits', 0)),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
