<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\Pages;

use App\Beneficiaries\CaseAccess;
use App\Beneficiaries\FieldMap;
use App\Filament\Resources\Beneficiaries\BeneficiaryResource;
use App\Filament\Resources\Beneficiaries\Schemas\CaseSchema;
use App\Models\Beneficiary;
use App\Models\BeneficiaryNote;
use App\Models\User;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * The case page: sections by tier, the status machine as actions, the log.
 *
 * ── Every view is recorded ──────────────────────────────────────────────────
 *
 * `beneficiary.viewed`, once per person per case per session, with the
 * highest tier shown — a second open in the same session updates nothing,
 * which keeps the hash-chained audit log readable while still answering
 * "who looked at this child's file in March" (design note §7). Revealing
 * the ID number is recorded every time, as a note on the case as well.
 *
 * ── No delete ───────────────────────────────────────────────────────────────
 *
 * Closing is the end of a case; destruction is the retention runner's.
 */
class ViewBeneficiary extends ViewRecord
{
    protected static string $resource = BeneficiaryResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->recordView();
    }

    protected function getHeaderActions(): array
    {
        $access = app(CaseAccess::class);
        $case = fn (): Beneficiary => $this->case();
        $user = fn (): User => auth()->user();

        return [
            EditAction::make()->visible(fn (): bool => $access->canEdit($user(), $case())),

            Action::make('reveal')
                ->label(__('Reveal ID number'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->visible(fn (): bool => $access->canSeeSensitive($user(), $case()) && filled($case()->ghana_card_number))
                ->requiresConfirmation()
                ->modalHeading(__('Show the Ghana Card number in full'))
                ->modalDescription(__('The reveal is written to the audit trail and to the case log with your name.'))
                ->action(function () use ($case, $user): void {
                    $c = $case();
                    app(AuditLogger::class)->record('beneficiary.viewed', sprintf('Revealed the ID number on case %s.', $c->case_reference), $c, $user(), ['tier' => 'C', 'reveal' => true]);
                    $c->note(__('ID number revealed.'), BeneficiaryNote::KIND_REVEAL, $user());

                    Notification::make()->title(__('Ghana Card number'))->body((string) $c->ghana_card_number)->persistent()->send();
                }),

            Action::make('note')
                ->label(__('Add a note'))
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn (): bool => $access->canNote($user(), $case()))
                ->schema([
                    Textarea::make('body')->label(__('Note'))->rows(5)->required()->maxLength(5000)
                        ->helperText(__('Dated and signed with your name; it cannot be edited afterwards. Write what you would be content for the person to read.')),
                ])
                ->action(function (array $data) use ($case, $user): void {
                    $case()->note((string) $data['body'], BeneficiaryNote::KIND_NOTE, $user());
                    Notification::make()->title(__('Note added'))->success()->send();
                    $this->reloadRecord();
                }),

            Action::make('submit')
                ->label(__('Submit for review'))
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $case()->status === Beneficiary::STATUS_DRAFT && $access->canProgress($user(), $case()))
                ->requiresConfirmation()
                ->action(function () use ($case): void {
                    $case()->submit();
                    $this->reloadRecord();
                }),

            Action::make('review')
                ->label(__('Start review'))
                ->icon('heroicon-o-magnifying-glass')
                ->visible(fn (): bool => $case()->status === Beneficiary::STATUS_SUBMITTED && $access->canDecide($user(), $case()))
                ->action(function () use ($case): void {
                    $case()->startReview();
                    $this->reloadRecord();
                }),

            Action::make('approve')
                ->label(__('Approve'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => in_array($case()->status, [Beneficiary::STATUS_SUBMITTED, Beneficiary::STATUS_UNDER_REVIEW], true) && $access->canDecide($user(), $case()))
                ->requiresConfirmation()
                ->modalDescription(__('Approving makes the case payable: Finance can see the name, the reference, the amount and the account.'))
                ->action(function () use ($case): void {
                    $case()->approve();
                    $this->reloadRecord();
                }),

            Action::make('decline')
                ->label(__('Decline'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => in_array($case()->status, [Beneficiary::STATUS_SUBMITTED, Beneficiary::STATUS_UNDER_REVIEW], true) && $access->canDecide($user(), $case()))
                ->schema([
                    Textarea::make('reason')->label(__('Why'))->rows(3)->required()->maxLength(1000)
                        ->helperText(__('For the file. What the applicant is told is a letter, written separately.')),
                ])
                ->action(function (array $data) use ($case): void {
                    $case()->decline((string) $data['reason']);
                    $this->reloadRecord();
                }),

            Action::make('withdraw')
                ->label(__('Withdraw'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => in_array($case()->status, [Beneficiary::STATUS_DRAFT, Beneficiary::STATUS_SUBMITTED, Beneficiary::STATUS_UNDER_REVIEW], true) && $access->canProgress($user(), $case()))
                ->requiresConfirmation()
                ->modalDescription(__('The applicant has withdrawn, or the application will not be completed. The record is kept for twelve months and then removed.'))
                ->action(function () use ($case): void {
                    $case()->withdraw();
                    $this->reloadRecord();
                }),

            Action::make('close')
                ->label(__('Close case'))
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn (): bool => $case()->status === Beneficiary::STATUS_APPROVED && $access->canProgress($user(), $case()))
                ->schema([
                    Select::make('outcome')->label(__('Outcome'))->options(CaseSchema::outcomes())->default(fn (): ?string => $case()->outcome)->required(),
                ])
                ->modalDescription(__('Closing starts the retention clock (six years for the record, two for medical documents) and writes the anonymous statistical record. Nothing is destroyed today.'))
                ->action(function (array $data) use ($case): void {
                    $case()->close((string) $data['outcome']);
                    $this->reloadRecord();
                }),

            Action::make('reassign')
                ->label(__('Reassign'))
                ->icon('heroicon-o-user-group')
                ->color('gray')
                ->visible(fn (): bool => $access->canReassign($user(), $case()) && ! $access->finished($case()))
                ->schema([
                    Select::make('case_worker_id')->label(__('Case worker'))
                        ->options(fn (): array => User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['Programme Officer', 'Safeguarding Lead']))->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => $case()->case_worker_id)
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data) use ($case, $user): void {
                    $c = $case();
                    $to = User::query()->findOrFail((int) $data['case_worker_id']);
                    $c->forceFill(['case_worker_id' => $to->getKey()])->save();
                    $c->note(__('Reassigned to :name.', ['name' => $to->name]), BeneficiaryNote::KIND_STATUS, $user());
                    $this->reloadRecord();
                }),
        ];
    }

    private function case(): Beneficiary
    {
        $record = $this->getRecord();

        if (! $record instanceof Beneficiary) {
            throw new \LogicException('This page shows a beneficiary case.');
        }

        return $record;
    }

    /** Once per session per case, at the highest tier shown. */
    private function recordView(): void
    {
        $user = auth()->user();
        $case = $this->case();

        if ($user === null) {
            return;
        }

        $views = app(CaseAccess::class)->views($user, $case);
        $tier = in_array(FieldMap::VIEW_SENSITIVE, $views, true) ? 'C' : (in_array(FieldMap::VIEW_WORKING, $views, true) ? 'B' : 'A');

        $seen = (array) session()->get('beneficiary_viewed', []);
        $key = (string) $case->getKey();

        if (isset($seen[$key]) && $seen[$key] >= $tier) {
            return;
        }

        app(AuditLogger::class)->record('beneficiary.viewed', sprintf('Opened case %s (tier %s).', $case->case_reference, $tier), $case, $user, ['tier' => $tier]);

        $seen[$key] = $tier;
        session()->put('beneficiary_viewed', $seen);
    }

    private function reloadRecord(): void
    {
        $this->record = $this->getRecord()->fresh(['division', 'project', 'focusArea', 'caseWorker']);
    }
}
