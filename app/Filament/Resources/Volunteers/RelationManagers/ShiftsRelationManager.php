<?php

declare(strict_types=1);

namespace App\Filament\Resources\Volunteers\RelationManagers;

use App\Models\Project;
use App\Models\VolunteerOpportunity;
use App\Models\VolunteerShift;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use RuntimeException;

/**
 * Shifts: planned, then confirmed.
 *
 * Confirming a shift as done writes the hours entry. A shift is scheduled
 * by whoever has `volunteers.manage`; the reminder goes the evening before
 * from `scghf:shift-reminders`.
 */
class ShiftsRelationManager extends RelationManager
{
    protected static string $relationship = 'shifts';

    protected static ?string $title = 'Shifts';

    /** Only somebody the checks say may work can be rostered. */
    protected function getCreateAuthorizationResponse(): Response
    {
        return auth()->user()->can('volunteers.manage') && $this->getOwnerRecord()->isAvailable()
            ? Response::allow()
            : Response::deny();
    }

    /**
     * The volunteer has no edit page — everything about them is an action —
     * so this manager sits on the view page and must not be read-only there.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('starts_at')->label(__('Starts'))->seconds(false)->minutesStep(15)->required(),
            DateTimePicker::make('ends_at')->label(__('Ends'))->seconds(false)->minutesStep(15)->required()->after('starts_at'),
            TextInput::make('activity')->label(__('What'))->maxLength(191)->helperText(__('"Saturday feeding", "Reading club" — what the reminder will say.')),
            TextInput::make('location')->label(__('Where'))->maxLength(191),
            Select::make('volunteer_opportunity_id')
                ->label(__('Role'))
                ->options(fn (): array => VolunteerOpportunity::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->nullable(),
            Select::make('project_id')
                ->label(__('Project'))
                ->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->nullable(),
            Textarea::make('notes')->label(__('Notes'))->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->label(__('When'))->dateTime('D j M Y, H:i')->sortable(),
                TextColumn::make('ends_at')->label(__('Until'))->dateTime('H:i'),
                TextColumn::make('activity')->label(__('What'))->placeholder('—')->wrap(),
                TextColumn::make('location')->label(__('Where'))->placeholder('—')->toggleable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        VolunteerShift::STATUS_COMPLETED => 'success',
                        VolunteerShift::STATUS_MISSED => 'danger',
                        VolunteerShift::STATUS_CANCELLED => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('reminder_sent_at')->label(__('Reminded'))->dateTime('j M, H:i')->placeholder(__('Not yet'))->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Schedule a shift'))
                    ->mutateDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label(__('Done'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (VolunteerShift $record): bool => $record->status === VolunteerShift::STATUS_SCHEDULED && $record->starts_at->isPast())
                    ->schema([
                        TextInput::make('hours')
                            ->label(__('Hours actually worked'))
                            ->numeric()
                            ->step(0.25)
                            ->minValue(0.25)
                            ->maxValue(24)
                            ->default(fn (VolunteerShift $record): float => round($record->minutes() / 60, 2)),
                    ])
                    ->action(function (VolunteerShift $record, array $data): void {
                        try {
                            $record->complete(auth()->user(), (int) round(((float) $data['hours']) * 60));
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('Shift confirmed and the hours written.'))->success()->send();
                    }),
                Action::make('missed')
                    ->label(__('Missed'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (VolunteerShift $record): bool => $record->status === VolunteerShift::STATUS_SCHEDULED && $record->starts_at->isPast())
                    ->requiresConfirmation()
                    ->action(fn (VolunteerShift $record) => $record->miss()),
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('gray')
                    ->visible(fn (VolunteerShift $record): bool => $record->status === VolunteerShift::STATUS_SCHEDULED)
                    ->requiresConfirmation()
                    ->action(fn (VolunteerShift $record) => $record->cancel()),
            ]);
    }
}
