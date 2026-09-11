<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events\RelationManagers;

use App\Filament\Support\ExportAction;
use App\Models\EventRegistration;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Who is coming.
 *
 * ── Behind `events.view_registrations`, not `events.view` ───────────────────
 *
 * A registration list is names, phone numbers and — in the accessibility and
 * dietary fields — health data. Being allowed to edit an event is not being
 * allowed to read that, so the tab is gated on its own permission, which is
 * seeded and, until now, protected nothing.
 *
 * ── The door list is the export ─────────────────────────────────────────────
 *
 * Name, guests, status and the two "needs" fields, because that is what the
 * person on the door and the person in the kitchen need. Not the email, not
 * the phone, not the consent evidence — those stay in the application.
 *
 * ── Check-in is a button ────────────────────────────────────────────────────
 *
 * It records who actually came, which is what the headcount on the impact
 * page should be built from rather than who said they would.
 */
class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    protected static ?string $title = 'Registrations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('events.view_registrations') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->description(fn (EventRegistration $record): string => (string) $record->email),

                TextColumn::make('phone')->label(__('Phone'))->placeholder('—'),

                TextColumn::make('headcount')
                    ->label(__('People'))
                    ->alignEnd()
                    ->state(fn (EventRegistration $record): int => $record->headcount()),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        EventRegistration::STATUS_REGISTERED => __('Coming'),
                        EventRegistration::STATUS_WAITLISTED => __('Waiting list'),
                        EventRegistration::STATUS_ATTENDED => __('Came'),
                        EventRegistration::STATUS_NO_SHOW => __('Did not come'),
                        EventRegistration::STATUS_CANCELLED => __('Cancelled'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        EventRegistration::STATUS_ATTENDED => 'success',
                        EventRegistration::STATUS_WAITLISTED => 'warning',
                        EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_NO_SHOW => 'gray',
                        default => 'info',
                    }),

                TextColumn::make('accessibility_needs')->label(__('Access needs'))->wrap()->placeholder('—')->toggleable(),
                TextColumn::make('dietary_needs')->label(__('Dietary'))->wrap()->placeholder('—')->toggleable(),

                IconColumn::make('photography_consent')
                    ->label(__('Photos'))
                    ->boolean()
                    ->placeholder(__('not asked'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    EventRegistration::STATUS_REGISTERED => __('Coming'),
                    EventRegistration::STATUS_WAITLISTED => __('Waiting list'),
                    EventRegistration::STATUS_ATTENDED => __('Came'),
                    EventRegistration::STATUS_NO_SHOW => __('Did not come'),
                    EventRegistration::STATUS_CANCELLED => __('Cancelled'),
                ]),
            ])
            ->recordActions([
                Action::make('checkIn')
                    ->label(__('Arrived'))
                    ->icon('heroicon-o-check')
                    ->visible(fn (EventRegistration $record): bool => in_array($record->status, [
                        EventRegistration::STATUS_REGISTERED, EventRegistration::STATUS_WAITLISTED,
                    ], true))
                    ->action(fn (EventRegistration $record) => $record->checkIn()),

                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (EventRegistration $record): bool => in_array($record->status, [
                        EventRegistration::STATUS_REGISTERED, EventRegistration::STATUS_WAITLISTED,
                    ], true))
                    ->action(fn (EventRegistration $record) => $record->cancel()),
            ])
            ->toolbarActions([
                ExportAction::make('report.generated', __('door list'), [
                    'Name' => 'name',
                    'People' => fn (EventRegistration $record) => $record->headcount(),
                    'Status' => 'status',
                    'Access needs' => 'accessibility_needs',
                    'Dietary' => 'dietary_needs',
                    'Reference' => 'reference',
                ]),
            ]);
    }
}
