<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\Tables;

use App\Community\EventNotifier;
use App\Filament\Support\ExportAction;
use App\Models\Event;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The events, soonest first.
 *
 * ── Cancelling asks why, and tells everybody ────────────────────────────────
 *
 * `Event::cancel()` refuses a blank reason, and the action here sends that
 * reason to every registered person and every person on the waiting list. It
 * confirms with the count, because an email to two hundred people is not
 * something to trigger while tidying a list.
 */
class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Event'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (Event $record): string => $record->is_online
                        ? __('Online')
                        : collect([$record->venue_name, $record->area])->filter()->implode(', ')),

                TextColumn::make('starts_at')
                    ->label(__('When'))
                    ->dateTime('D j M Y, H:i')
                    ->sortable(),

                TextColumn::make('registered_count')
                    ->label(__('Registered'))
                    ->alignEnd()
                    ->state(fn (Event $record): string => ! $record->registration_required
                        ? '—'
                        : ($record->capacity === null
                            ? (string) $record->registered_count
                            : $record->registered_count.' / '.$record->capacity))
                    ->color(fn (Event $record): string => $record->isFull() ? 'warning' : 'gray'),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Event::STATUS_SCHEDULED => __('Going ahead'),
                        Event::STATUS_CANCELLED => __('Cancelled'),
                        Event::STATUS_POSTPONED => __('Postponed'),
                        Event::STATUS_COMPLETED => __('Took place'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Event::STATUS_CANCELLED => 'danger',
                        Event::STATUS_POSTPONED => 'warning',
                        Event::STATUS_COMPLETED => 'gray',
                        default => 'success',
                    }),

                IconColumn::make('is_published')->label(__('Shown'))->boolean(),
            ])
            ->filters([
                Filter::make('upcoming')
                    ->label(__('Upcoming'))
                    ->default()
                    ->query(fn (Builder $query) => $query->where('starts_at', '>=', now()->startOfDay())),
                SelectFilter::make('status')->label(__('Status'))->options([
                    Event::STATUS_SCHEDULED => __('Going ahead'),
                    Event::STATUS_POSTPONED => __('Postponed'),
                    Event::STATUS_CANCELLED => __('Cancelled'),
                    Event::STATUS_COMPLETED => __('Took place'),
                ]),
                TernaryFilter::make('is_published')->label(__('Shown on the site')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                self::cancelAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                ExportAction::make('report.generated', __('events'), [
                    'Event' => 'title',
                    'Starts' => fn (Event $record) => $record->starts_at->format('Y-m-d H:i'),
                    'Where' => fn (Event $record) => $record->is_online ? 'online' : collect([$record->venue_name, $record->area, $record->region])->filter()->implode(', '),
                    'Registered' => 'registered_count',
                    'Capacity' => 'capacity',
                    'Status' => 'status',
                ]),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Event $record): bool => $record->status !== Event::STATUS_CANCELLED && ! $record->hasFinished())
            ->modalHeading(fn (Event $record): string => __('Cancel :title', ['title' => $record->title]))
            ->modalDescription(fn (Event $record): string => trans_choice(
                '{0}Nobody has registered, so nobody needs telling.|{1}One person has registered and will be emailed the reason.|[2,*]:count people have registered and will be emailed the reason.',
                $record->registrations()->whereIn('status', ['registered', 'waitlisted'])->count(),
                ['count' => $record->registrations()->whereIn('status', ['registered', 'waitlisted'])->count()],
            ))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Why'))
                    ->required()
                    ->rows(3)
                    ->helperText(__('This is what the people who registered will read. "Cancelled" on its own is not an explanation.')),
            ])
            ->action(function (Event $record, array $data): void {
                $record->cancel((string) $data['reason']);

                $told = app(EventNotifier::class)->cancelled($record, (string) $data['reason']);

                Notification::make()
                    ->title(__('Cancelled.'))
                    ->body(trans_choice('{0}Nobody to tell.|{1}One person has been told.|[2,*]:count people have been told.', $told, ['count' => $told]))
                    ->success()
                    ->send();
            });
    }
}
