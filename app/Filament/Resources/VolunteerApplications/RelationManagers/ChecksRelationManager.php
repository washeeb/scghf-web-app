<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerApplications\RelationManagers;

use App\Models\SafeguardingCheck;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

/**
 * The safeguarding checks on an application.
 *
 * ── Each outcome is a decision with a name on it ────────────────────────────
 *
 * "Passed" needs a reference — the certificate number, the referee spoken
 * to, the interview date — and is recorded against the signed-in user. The
 * model refuses anything less, and the form asks for exactly what the model
 * will accept, so the refusal is never the first thing somebody sees.
 *
 * "Waived" needs a reason and is recorded against the person who waived it.
 * Waiving a check on somebody who will work unsupervised with vulnerable
 * people is a decision that has to be owned.
 *
 * "Failed" ends the application. `approve()` refuses while any check is
 * failed, and that decision is revisited explicitly, not overridden.
 */
class ChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'checks';

    protected static ?string $title = 'Safeguarding checks';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (SafeguardingCheck $record): string => $record->label())
            ->columns([
                TextColumn::make('check_type')
                    ->label(__('Check'))
                    ->state(fn (SafeguardingCheck $record): string => $record->label())
                    ->description(fn (SafeguardingCheck $record): ?string => config("compliance.safeguarding.required_checks.{$record->check_type}.description")),

                TextColumn::make('outcome')
                    ->label(__('Outcome'))
                    ->badge()
                    ->formatStateUsing(fn (string $state, SafeguardingCheck $record): string => match (true) {
                        $record->hasExpired() => __('Expired'),
                        $state === SafeguardingCheck::OUTCOME_PASSED => __('Passed'),
                        $state === SafeguardingCheck::OUTCOME_FAILED => __('Failed'),
                        $state === SafeguardingCheck::OUTCOME_WAIVED => __('Waived'),
                        default => __('Pending'),
                    })
                    ->color(fn (string $state, SafeguardingCheck $record): string => match (true) {
                        $record->hasExpired(), $state === SafeguardingCheck::OUTCOME_FAILED => 'danger',
                        $state === SafeguardingCheck::OUTCOME_PASSED => 'success',
                        $state === SafeguardingCheck::OUTCOME_WAIVED => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('reference')->label(__('Reference'))->placeholder('—'),
                TextColumn::make('completed_on')->label(__('On'))->date('j M Y')->placeholder('—'),
                TextColumn::make('expires_on')->label(__('Expires'))->date('j M Y')->placeholder('—'),
                TextColumn::make('verifiedBy.name')->label(__('Verified by'))->placeholder('—'),
            ])
            ->recordActions([
                Action::make('pass')
                    ->label(__('Passed'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (SafeguardingCheck $record): bool => $record->outcome !== SafeguardingCheck::OUTCOME_PASSED || $record->hasExpired())
                    ->schema([
                        TextInput::make('reference')
                            ->label(__('Reference'))
                            ->required()
                            ->maxLength(191)
                            ->helperText(__('The certificate number, the referee spoken to, the date and interviewer. A check with no evidence is a claim.')),
                        DatePicker::make('completed_on')->label(__('Completed on'))->default(now()),
                        DatePicker::make('expires_on')->label(__('Expires'))->helperText(__('For a police clearance. Empty for a check that does not expire.')),
                        Textarea::make('notes')->label(__('Notes'))->rows(2),
                    ])
                    ->action(fn (SafeguardingCheck $record, array $data) => self::record($record, [
                        ...$data,
                        'outcome' => SafeguardingCheck::OUTCOME_PASSED,
                        'verified_by' => auth()->id(),
                    ])),

                Action::make('fail')
                    ->label(__('Failed'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (SafeguardingCheck $record): bool => $record->outcome === SafeguardingCheck::OUTCOME_PENDING)
                    ->requiresConfirmation()
                    ->modalDescription(__('The application cannot be approved while a check is recorded as failed. This is revisited explicitly, never overridden.'))
                    ->schema([
                        Textarea::make('notes')->label(__('What was found'))->required()->rows(3),
                    ])
                    ->action(fn (SafeguardingCheck $record, array $data) => self::record($record, [
                        ...$data,
                        'outcome' => SafeguardingCheck::OUTCOME_FAILED,
                        'verified_by' => auth()->id(),
                        'completed_on' => now()->toDateString(),
                    ])),

                Action::make('waive')
                    ->label(__('Waive'))
                    ->icon('heroicon-o-hand-raised')
                    ->color('warning')
                    ->visible(fn (SafeguardingCheck $record): bool => $record->outcome === SafeguardingCheck::OUTCOME_PENDING)
                    ->modalDescription(__('Waiving a check on somebody who may work with vulnerable people is a decision that has to be owned. Your name goes on it.'))
                    ->schema([
                        Textarea::make('waiver_reason')->label(__('Why'))->required()->rows(3),
                    ])
                    ->action(fn (SafeguardingCheck $record, array $data) => self::record($record, [
                        ...$data,
                        'outcome' => SafeguardingCheck::OUTCOME_WAIVED,
                        'waived_by' => auth()->id(),
                        'completed_on' => now()->toDateString(),
                    ])),
            ]);
    }

    /** @param array<string, mixed> $data */
    private static function record(SafeguardingCheck $check, array $data): void
    {
        try {
            $check->fill($data)->save();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('Recorded.'))->success()->send();
    }
}
