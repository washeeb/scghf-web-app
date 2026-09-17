<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerApplications\Pages;

use App\Community\VolunteerNotifier;
use App\Filament\Concerns\AuditsRecordAccess;
use App\Filament\Resources\VolunteerApplications\VolunteerApplicationResource;
use App\Models\VolunteerApplication;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Deciding an application.
 *
 * ── Approve refuses out loud ────────────────────────────────────────────────
 *
 * `VolunteerApplication::approve()` refuses while any required check is
 * outstanding or any has failed, naming what is missing. The refusal is shown
 * as it is, so the answer is to go and record the check rather than to look
 * for a way round.
 *
 * ── Decline writes two things ───────────────────────────────────────────────
 *
 * The reason, for the file. And the message, for the applicant — written each
 * time, because "the police check came back" is not a sentence to send
 * automatically.
 */
class ViewVolunteerApplication extends ViewRecord
{
    protected static string $resource = VolunteerApplicationResource::class;

    use AuditsRecordAccess;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->auditAccess(
            'volunteer.pii_viewed',
            'Opened volunteer application '.$this->getRecord()->getKey(),
            $this->getRecord(),
            shown: auth()->user()->can('volunteers.view_pii'),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')
                ->label(__('Start reviewing'))
                ->icon('heroicon-o-eye')
                ->visible(fn (): bool => $this->getRecord()->status === VolunteerApplication::STATUS_SUBMITTED)
                ->action(function (): void {
                    $this->getRecord()->forceFill([
                        'status' => VolunteerApplication::STATUS_UNDER_REVIEW,
                        'last_activity_at' => now(),
                    ])->save();

                    $this->getRecord()->refresh();
                    $this->fillForm();
                }),

            Action::make('notes')
                ->label(__('Add a note'))
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->schema([
                    Textarea::make('assessor_notes')
                        ->label(__('Assessor notes'))
                        ->rows(4)
                        ->default(fn (): ?string => $this->getRecord()->assessor_notes),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->forceFill([
                        'assessor_notes' => $data['assessor_notes'],
                        'last_activity_at' => now(),
                    ])->save();

                    $this->getRecord()->refresh();
                    $this->fillForm();
                }),

            Action::make('shortlist')
                ->label(__('Shortlist'))
                ->icon('heroicon-o-star')
                ->color('info')
                ->visible(fn (): bool => in_array($this->getRecord()->status, [
                    VolunteerApplication::STATUS_SUBMITTED, VolunteerApplication::STATUS_UNDER_REVIEW,
                ], true))
                ->requiresConfirmation()
                ->modalDescription(__('Tells the applicant they are through to the next stage and somebody will be in touch about an interview.'))
                ->action(function (): void {
                    $this->getRecord()->shortlist(auth()->user());
                    app(VolunteerNotifier::class)->shortlisted($this->getRecord()->fresh()->load('opportunity'));

                    Notification::make()->title(__('Shortlisted, and the applicant has been told.'))->success()->send();

                    $this->getRecord()->refresh();
                    $this->fillForm();
                }),

            Action::make('interview')
                ->label(fn (): string => $this->getRecord()->interview_at ? __('Rearrange interview') : __('Arrange interview'))
                ->icon('heroicon-o-calendar')
                ->color('info')
                ->visible(fn (): bool => in_array($this->getRecord()->status, [
                    VolunteerApplication::STATUS_SUBMITTED, VolunteerApplication::STATUS_UNDER_REVIEW,
                    VolunteerApplication::STATUS_SHORTLISTED,
                ], true))
                ->schema([
                    DateTimePicker::make('interview_at')
                        ->label(__('When'))
                        ->seconds(false)
                        ->minutesStep(15)
                        ->required()
                        ->minDate(now())
                        ->default(fn (): ?Carbon => $this->getRecord()->interview_at),
                    TextInput::make('location')
                        ->label(__('Where'))
                        ->required()
                        ->maxLength(191)
                        ->default(fn (): ?string => $this->getRecord()->interview_location ?: (string) setting('contact.address', ''))
                        ->helperText(__('The office address, a phone call, a video link — whatever the applicant needs to turn up to.')),
                ])
                ->action(function (array $data): void {
                    try {
                        $this->getRecord()->scheduleInterview(auth()->user(), Carbon::parse((string) $data['interview_at']), (string) $data['location']);
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Not arranged'))->body($e->getMessage())->danger()->send();

                        return;
                    }

                    app(VolunteerNotifier::class)->interview($this->getRecord()->fresh()->load('opportunity'));

                    Notification::make()->title(__('Interview arranged, and the applicant has been told.'))->success()->send();

                    $this->getRecord()->refresh();
                    $this->fillForm();
                }),

            Action::make('interviewed')
                ->label(__('Interview done'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->status === VolunteerApplication::STATUS_SHORTLISTED)
                ->schema([
                    Textarea::make('notes')
                        ->label(__('How it went'))
                        ->rows(4)
                        ->helperText(__('For the file. Seen by staff only.')),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->markInterviewed(auth()->user(), (string) ($data['notes'] ?? ''));

                    Notification::make()->title(__('Recorded.'))->success()->send();

                    $this->getRecord()->refresh();
                    $this->fillForm();
                }),

            Action::make('approve')
                ->label(__('Approve'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => in_array($this->getRecord()->status, VolunteerApplication::OPEN_STATUSES, true))
                ->modalDescription(fn (): string => ($outstanding = $this->getRecord()->outstandingChecks()) === []
                    ? __('Every required check is recorded. Approving creates the volunteer record and tells the applicant.')
                    : __('Outstanding checks: :checks. Approval will be refused until they are recorded.', [
                        'checks' => implode(', ', array_map(
                            fn (string $type): string => (string) config("compliance.safeguarding.required_checks.{$type}.label", $type),
                            $outstanding,
                        )),
                    ]))
                ->schema([
                    TextInput::make('role')
                        ->label(__('Role title'))
                        ->maxLength(191)
                        ->default(fn (): ?string => $this->getRecord()->opportunity?->title)
                        ->helperText(__('What goes on their volunteer record.')),
                ])
                ->action(function (array $data): void {
                    try {
                        $this->getRecord()->approve(auth()->user(), (string) ($data['role'] ?? ''));
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Not approved'))->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    app(VolunteerNotifier::class)->approved($this->getRecord()->fresh()->load('opportunity'));

                    Notification::make()->title(__('Approved, and the applicant has been told.'))->success()->send();

                    $this->getRecord()->refresh();
                    $this->fillForm();
                }),

            Action::make('decline')
                ->label(__('Decline'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->getRecord()->status, VolunteerApplication::OPEN_STATUSES, true))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('Reason, for the file'))
                        ->required()
                        ->rows(2)
                        ->helperText(__('Seen by staff only.')),

                    Textarea::make('message')
                        ->label(__('What to tell the applicant'))
                        ->required()
                        ->rows(4)
                        ->helperText(__('Sent to them as written. Kind, and honest about whether they may apply again.')),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->decline(auth()->user(), (string) $data['reason']);

                    app(VolunteerNotifier::class)->declined($this->getRecord()->fresh(), (string) $data['message']);

                    Notification::make()->title(__('Declined, and the applicant has been told.'))->success()->send();

                    $this->getRecord()->refresh();
                    $this->fillForm();
                }),
        ];
    }
}
