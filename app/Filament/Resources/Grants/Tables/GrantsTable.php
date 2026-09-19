<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\Tables;

use App\Filament\Support\ExportAction;
use App\Models\Funder;
use App\Models\Grant;
use App\ValueObjects\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grants, the nearest deadline first.
 */
class GrantsTable
{
    public static function configure(Table $table): Table
    {
        $money = fn (mixed $state): string => $state instanceof Money ? $state->format() : '—';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['funder', 'project', 'owner'])->withCount(['obligations as obligations_due_count' => fn (Builder $q) => $q->whereNull('completed_on')->whereDate('due_on', '<=', now()->addDays(14))]))
            ->defaultSort('deadline_on', 'asc')
            ->columns([
                TextColumn::make('title')->label(__('Grant'))->searchable()->sortable()->wrap()
                    ->description(fn (Grant $record): string => (string) $record->funder?->name),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (?string $state): string => Grant::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        Grant::STATUS_AWARDED => 'success',
                        Grant::STATUS_DECLINED => 'danger',
                        Grant::STATUS_SUBMITTED => 'warning',
                        Grant::STATUS_CLOSED => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('amount_requested')->label(__('Asked'))->formatStateUsing($money)->alignEnd()->toggleable(),
                TextColumn::make('amount_awarded')->label(__('Awarded'))->formatStateUsing($money)->alignEnd(),
                TextColumn::make('spent')->label(__('Spent'))->state(fn (Grant $record): string => $record->status === Grant::STATUS_AWARDED || $record->status === Grant::STATUS_CLOSED ? $record->spent()->format() : '—')->alignEnd(),
                TextColumn::make('deadline_on')->label(__('Deadline'))->date('j M Y')->placeholder('—')->sortable()
                    ->color(fn (Grant $record): ?string => $record->deadline_on !== null && ! $record->isFinished() && $record->status !== Grant::STATUS_AWARDED && $record->deadline_on->lte(now()->addDays(14)) ? 'danger' : null),
                TextColumn::make('obligations_due_count')->label(__('Due'))->badge()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? trans_choice('1 obligation|:count obligations', $state, ['count' => $state]) : '')
                    ->color('warning'),
                TextColumn::make('project.title')->label(__('Project'))->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('owner.name')->label(__('Owner'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(Grant::STATUSES),
                Filter::make('open')->label(__('Open only'))->query(fn (Builder $q): Builder => $q->whereNotIn('status', [Grant::STATUS_DECLINED, Grant::STATUS_CLOSED]))->default(),
                SelectFilter::make('funder_id')->label(__('Funder'))->options(fn (): array => Funder::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('grants'), [
                    'Title' => 'title',
                    'Funder' => fn (Grant $record): string => (string) $record->funder?->name,
                    'Status' => fn (Grant $record): string => Grant::STATUSES[$record->status] ?? $record->status,
                    'Asked' => fn (Grant $record): string => $record->amount_requested?->format() ?? '',
                    'Awarded' => fn (Grant $record): string => $record->amount_awarded?->format() ?? '',
                    'Spent' => fn (Grant $record): string => $record->spent()->format(),
                    'Deadline' => fn (Grant $record): string => (string) $record->deadline_on?->toDateString(),
                    'Project' => fn (Grant $record): string => (string) $record->project?->title,
                    'Owner' => fn (Grant $record): string => (string) $record->owner?->name,
                ], ['funder', 'project', 'owner']),
            ]);
    }
}
