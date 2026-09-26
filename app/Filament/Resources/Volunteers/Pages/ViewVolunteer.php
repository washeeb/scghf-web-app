<?php

declare(strict_types=1);

namespace App\Filament\Resources\Volunteers\Pages;

use App\Community\VolunteerNotifier;
use App\Filament\Concerns\AuditsRecordAccess;
use App\Filament\Resources\Volunteers\VolunteerResource;
use App\Models\Volunteer;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

/**
 * One volunteer, and the things that can happen to them.
 *
 * A concern suspends at once and needs a written outcome to lift; leaving
 * closes the record and sends the thank-you with the hours they gave. The
 * clearance button re-reads the checks on the application rather than
 * letting anybody tick "cleared" by hand.
 */
class ViewVolunteer extends ViewRecord
{
    use AuditsRecordAccess;

    protected static string $resource = VolunteerResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->auditAccess(
            'volunteer.pii_viewed',
            'Opened volunteer '.$this->getRecord()->getKey(),
            $this->getRecord(),
            shown: auth()->user()->can('volunteers.view_pii'),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshClearance')
                ->label(__('Re-check clearance'))
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->involves_vulnerable_contact)
                ->action(function (): void {
                    $cleared = $this->getRecord()->refreshClearance();

                    Notification::make()
                        ->title($cleared ? __('Cleared: every check is current.') : __('NOT cleared. A check is missing, failed or expired — see the application.'))
                        ->{$cleared ? 'success' : 'danger'}()
                        ->send();

                    $this->refreshRecord();
                }),

            Action::make('editRole')
                ->label(__('Role'))
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->schema([
                    TextInput::make('role')->label(__('Role title'))->maxLength(191)->default(fn (): ?string => $this->getRecord()->role),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->forceFill(['role' => $data['role'] ?: null])->save();
                    $this->refreshRecord();
                }),

            Action::make('concern')
                ->label(__('Raise a concern'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->visible(fn (): bool => ! $this->getRecord()->hasOpenConcern() && $this->getRecord()->status !== Volunteer::STATUS_LEFT)
                ->modalDescription(__('Suspends the volunteer immediately, as a precaution. Write what was noticed and when — it is the only record anybody will have to work from.'))
                ->schema([
                    Textarea::make('note')->label(__('What was noticed'))->required()->rows(5),
                ])
                ->action(function (array $data): void {
                    try {
                        $this->getRecord()->raiseConcern(auth()->user(), (string) $data['note']);
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Concern recorded and the volunteer suspended.'))->warning()->send();
                    $this->refreshRecord();
                }),

            Action::make('resolveConcern')
                ->label(__('Close the concern'))
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->hasOpenConcern())
                ->schema([
                    Textarea::make('outcome')->label(__('Outcome'))->required()->rows(4)
                        ->helperText(__('What was found, who decided, and why the volunteer may (or may not) return.')),
                ])
                ->action(function (array $data): void {
                    try {
                        $this->getRecord()->resolveConcern(auth()->user(), (string) $data['outcome']);
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Concern closed; the volunteer is active again.'))->success()->send();
                    $this->refreshRecord();
                }),

            Action::make('inactive')
                ->label(__('Mark inactive'))
                ->icon('heroicon-o-pause-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->status === Volunteer::STATUS_ACTIVE)
                ->requiresConfirmation()
                ->modalDescription(__('For somebody taking a break. They stay on the books and are not rostered; nothing is sent.'))
                ->action(function (): void {
                    $this->getRecord()->forceFill(['status' => Volunteer::STATUS_INACTIVE])->save();
                    $this->refreshRecord();
                }),

            Action::make('active')
                ->label(__('Mark active'))
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->status === Volunteer::STATUS_INACTIVE)
                ->action(function (): void {
                    $this->getRecord()->forceFill(['status' => Volunteer::STATUS_ACTIVE])->save();
                    $this->refreshRecord();
                }),

            Action::make('leave')
                ->label(__('Record as left'))
                ->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->status !== Volunteer::STATUS_LEFT && ! $this->getRecord()->hasOpenConcern())
                ->modalDescription(__('Closes the record and sends a thank-you with the hours they gave. The retention clock starts from today.'))
                ->schema([
                    TextInput::make('reason')->label(__('Reason, for the file'))->maxLength(191),
                ])
                ->action(function (array $data): void {
                    $volunteer = $this->getRecord();
                    $volunteer->refreshTotalHours();
                    $volunteer->leave((string) ($data['reason'] ?? ''));

                    app(VolunteerNotifier::class)->thankYou($volunteer->fresh());

                    Notification::make()->title(__('Recorded, and thanked.'))->success()->send();
                    $this->refreshRecord();
                }),
        ];
    }

    private function refreshRecord(): void
    {
        $this->getRecord()->refresh();
        $this->fillForm();
    }
}
