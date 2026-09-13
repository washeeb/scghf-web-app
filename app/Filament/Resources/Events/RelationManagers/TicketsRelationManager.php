<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use App\Models\IssuedTicket;
use App\Support\Features;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * The door list, by code.
 *
 * A volunteer with a phone types the code off somebody's screen, finds the
 * row, presses Check in. A code that was already used says when; a
 * cancelled one says so. Names are searchable too, for the person who
 * cannot find the email.
 */
class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'issuedTickets';

    protected static ?string $title = 'Tickets';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return app(Features::class)->enabled('event_ticketing')
            && (auth()->user()?->can('events.view_registrations') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['registration', 'ticketType', 'order']))
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label(__('Code'))->fontFamily('mono')->searchable()->copyable(),
                TextColumn::make('holder_name')->label(__('Holder'))->searchable(),
                TextColumn::make('registration.email')->label(__('Email'))->searchable(),
                TextColumn::make('ticketType.name')->label(__('Ticket'))->placeholder('—'),
                TextColumn::make('order.reference')->label(__('Order'))->fontFamily('mono')->placeholder('—'),
                TextColumn::make('checked_in_at')->label(__('Checked in'))->dateTime('j M, H:i')->placeholder(__('Not yet')),
                TextColumn::make('cancelled_at')->label(__('Cancelled'))->dateTime('j M')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('checked_in')
                    ->label(__('Checked in'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('checked_in_at'),
                        false: fn (Builder $q) => $q->whereNull('checked_in_at'),
                    ),
            ])
            ->recordActions([
                Action::make('checkIn')
                    ->label(__('Check in'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (IssuedTicket $t): bool => $t->isValid())
                    ->action(function (IssuedTicket $t): void {
                        try {
                            $t->checkIn(auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__(':name is in.', ['name' => $t->holder_name]))->success()->send();
                    }),

                Action::make('undo')
                    ->label(__('Undo'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (IssuedTicket $t): bool => $t->checked_in_at !== null && $t->checked_in_at->gt(now()->subMinutes(10)))
                    ->requiresConfirmation()
                    ->action(fn (IssuedTicket $t) => $t->forceFill(['checked_in_at' => null, 'checked_in_by' => null])->save()),
            ]);
    }
}
