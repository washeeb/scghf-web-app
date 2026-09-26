<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Pages;

use App\Filament\Resources\Payouts\PayoutResource;
use App\Media\MediaLibrary;
use App\Models\Payout;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

/**
 * The workflow as buttons, each one the model's own method with its own
 * refusal: submit (the requester), approve (somebody else, with
 * `payouts.approve`), reject with a reason, mark paid with the evidence,
 * cancel with a reason. The model's messages are shown as they are — the
 * two-person rule says why in its own words.
 */
class ViewPayout extends ViewRecord
{
    protected static string $resource = PayoutResource::class;

    protected function getHeaderActions(): array
    {
        $payout = fn (): Payout => $this->payout();
        $can = fn (string $p): bool => auth()->user()?->can($p) ?? false;

        return [
            Action::make('submit')
                ->label(__('Submit for approval'))
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $payout()->status === Payout::STATUS_DRAFT && $can('payouts.request'))
                ->requiresConfirmation()
                ->action(fn () => $this->attempt(fn () => $payout()->submit(auth()->user()), __('Submitted. Somebody else must approve it.'))),

            Action::make('approve')
                ->label(__('Approve'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $payout()->status === Payout::STATUS_PENDING && $can('payouts.approve'))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('Approve :amount to :payee. You cannot approve a payout you requested.', ['amount' => (string) $payout()->amount, 'payee' => $payout()->payee_name]))
                ->action(fn () => $this->attempt(fn () => $payout()->approve(auth()->user()), __('Approved. It can be paid.'))),

            Action::make('reject')
                ->label(__('Reject'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $payout()->status === Payout::STATUS_PENDING && $can('payouts.approve'))
                ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(3)])
                ->action(fn (array $data) => $this->attempt(fn () => $payout()->reject(auth()->user(), (string) $data['reason']), __('Rejected.'))),

            Action::make('paid')
                ->label(__('Mark paid'))
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (): bool => $payout()->status === Payout::STATUS_APPROVED && $can('payouts.mark_paid'))
                ->schema([
                    FileUpload::make('evidence')
                        ->label(__('Evidence'))
                        ->storeFiles(false)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(10240)
                        ->required(fn (): bool => $payout()->evidence_media_id === null)
                        ->helperText(__('The receipt, the signed collection slip or the bank advice. A payout cannot be marked paid without it.')),
                ])
                ->action(function (array $data) use ($payout): void {
                    $file = $data['evidence'] ?? null;
                    $media = null;

                    if ($file instanceof TemporaryUploadedFile) {
                        try {
                            $media = app(MediaLibrary::class)->add($file, null, [], auth()->user(), private: true);
                        } catch (RuntimeException $e) {
                            Notification::make()->title(__('The evidence was not accepted'))->body($e->getMessage())->danger()->send();

                            return;
                        }
                    }

                    $this->attempt(fn () => $payout()->markPaid(auth()->user(), $media), __('Paid, with the evidence on file.'));
                }),

            Action::make('cancel')
                ->label(__('Cancel'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => in_array($payout()->status, [Payout::STATUS_DRAFT, Payout::STATUS_PENDING, Payout::STATUS_APPROVED], true) && $can('payouts.request'))
                ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)])
                ->action(fn (array $data) => $this->attempt(fn () => $payout()->cancel((string) $data['reason']), __('Cancelled.'))),
        ];
    }

    private function attempt(callable $step, string $done): void
    {
        try {
            $step();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            return;
        }

        Notification::make()->title($done)->success()->send();
        $this->record = $this->payout()->fresh(['division', 'project', 'cause', 'grant', 'beneficiary', 'requester', 'approver', 'evidence']);
    }

    private function payout(): Payout
    {
        $record = $this->getRecord();

        if (! $record instanceof Payout) {
            throw new \LogicException('This page shows a payout.');
        }

        return $record;
    }
}
