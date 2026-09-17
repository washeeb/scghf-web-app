<?php

declare(strict_types=1);

namespace App\Filament\Resources\Volunteers\RelationManagers;

use App\Models\Project;
use App\Models\VolunteerHour;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
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
 * Hours given.
 *
 * Anybody with `volunteers.log_hours` may record an entry; verifying it
 * needs `volunteers.manage` AND a different person from the one who
 * recorded it — the model refuses otherwise. Only verified hours reach the
 * total the foundation reports.
 */
class HoursRelationManager extends RelationManager
{
    protected static string $relationship = 'hours';

    protected static ?string $title = 'Hours';

    /** `volunteers.log_hours` is the permission for exactly this. */
    protected function getCreateAuthorizationResponse(): Response
    {
        return auth()->user()->can('volunteers.log_hours') || auth()->user()->can('volunteers.manage')
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
            DatePicker::make('worked_on')->label(__('Day'))->required()->maxDate(now())->default(now()),
            TextInput::make('hours')
                ->label(__('Hours'))
                ->numeric()
                ->step(0.25)
                ->minValue(0.25)
                ->maxValue(24)
                ->required()
                ->helperText(__('Quarter hours are fine: 2.5 is two and a half hours.')),
            TextInput::make('activity')->label(__('What they did'))->maxLength(191),
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
            ->defaultSort('worked_on', 'desc')
            ->columns([
                TextColumn::make('worked_on')->label(__('Day'))->date('D j M Y')->sortable(),
                TextColumn::make('minutes')
                    ->label(__('Hours'))
                    ->formatStateUsing(fn (int $state): string => number_format($state / 60, 2)),
                TextColumn::make('activity')->label(__('Activity'))->placeholder('—')->wrap(),
                TextColumn::make('project.title')->label(__('Project'))->placeholder('—')->toggleable(),
                TextColumn::make('verified_at')
                    ->label(__('Verified'))
                    ->badge()
                    ->formatStateUsing(fn (VolunteerHour $record): string => $record->verifiedBy?->name ? __('By :name', ['name' => $record->verifiedBy->name]) : __('Yes'))
                    ->color('success')
                    ->placeholder(__('Not yet')),
                TextColumn::make('recordedBy.name')->label(__('Recorded by'))->placeholder('—')->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('Log hours'))
                    ->mutateDataUsing(function (array $data): array {
                        $data['minutes'] = (int) round(((float) $data['hours']) * 60);
                        $data['recorded_by'] = auth()->id();
                        unset($data['hours']);

                        return $data;
                    })
                    ->successNotificationTitle(__('Logged. A second person verifies it before it counts.')),
            ])
            ->recordActions([
                Action::make('verify')
                    ->label(__('Verify'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (VolunteerHour $record): bool => $record->verified_at === null && auth()->user()->can('volunteers.manage'))
                    ->requiresConfirmation()
                    ->action(function (VolunteerHour $record): void {
                        try {
                            $record->verify(auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('Verified.'))->success()->send();
                    }),
            ]);
    }
}
