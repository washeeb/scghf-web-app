<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Pages;

use App\Enums\DonationStatus;
use App\Filament\Concerns\AuditsRecordAccess;
use App\Filament\Resources\Donations\DonationResource;
use App\Filament\Support\MoneyField;
use App\Models\Donation;
use App\Payments\DonationNotifier;
use App\Payments\PaymentManager;
use App\Payments\ReceiptIssuer;
use App\Payments\RefundService;
use App\Support\AuditLogger;
use App\ValueObjects\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

/**
 * What can be done to a gift.
 *
 * Every action is recorded — through the activity log on the model and, for
 * the ones that matter to an auditor, through `AuditLogger` — and none of them
 * edits the money. A refund is REQUESTED here and approved by a different
 * person on the Refunds screen; the model refuses a self-approval, so the
 * separation is not a matter of remembering.
 */
class ViewDonation extends ViewRecord
{
    protected static string $resource = DonationResource::class;

    use AuditsRecordAccess;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // The gift itself is a financial record; the donor's contact details
        // on it are personal data, and only shown to `donations.view_pii`.
        $this->auditAccess(
            'donor.pii_viewed',
            'Opened donation '.$this->record()->reference.' with donor details',
            $this->record(),
            shown: auth()->user()->can('donations.view_pii'),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label(__('Check with the gateway'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->record()->status === DonationStatus::Pending && $this->record()->transaction !== null)
                ->action(function (): void {
                    $status = app(PaymentManager::class)->verifyAndSettle($this->record()->transaction);

                    Notification::make()->title(__('The gateway says: :status', ['status' => $status->value]))->info()->send();
                    $this->reloadRecord();
                }),

            Action::make('resendReceipt')
                ->label(__('Resend receipt'))
                ->icon('heroicon-o-envelope')
                ->visible(fn (): bool => $this->record()->status === DonationStatus::Completed && filled($this->record()->donor_email)
                    && auth()->user()->can('donations.receipt_reissue'))
                ->requiresConfirmation()
                ->modalDescription(__('Issues the receipt if it has not been, and queues the acknowledgement email again with a fresh download link.'))
                ->action(function (): void {
                    $donation = $this->record();

                    try {
                        app(ReceiptIssuer::class)->issue($donation, auth()->user());
                    } catch (RuntimeException $e) {
                        Notification::make()->title(__('Could not issue the receipt'))->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    // A fresh key, so the outbox does not treat it as the duplicate it is.
                    app(DonationNotifier::class)->resend($donation->fresh());
                    app(AuditLogger::class)->record('receipt.resent', 'Receipt resent for '.$donation->reference, $donation, auth()->user(), ['reference' => $donation->reference]);

                    Notification::make()->title(__('Queued.'))->success()->send();
                    $this->reloadRecord();
                }),

            Action::make('reconcile')
                ->label(__('Mark reconciled'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->record()->status === DonationStatus::Completed
                    && $this->record()->transaction !== null
                    && $this->record()->transaction->reconciled_at === null
                    && auth()->user()->can('payments.reconcile'))
                ->requiresConfirmation()
                ->modalDescription(__('Records that this payment has been matched to a settlement on the bank statement or the Paystack payout report, by you, today.'))
                ->action(function (): void {
                    $donation = $this->record();
                    $donation->transaction->forceFill(['reconciled_at' => now()])->save();
                    app(AuditLogger::class)->record('donation.reconciled', 'Donation '.$donation->reference.' marked reconciled', $donation, auth()->user(), ['reference' => $donation->reference]);

                    Notification::make()->title(__('Reconciled.'))->success()->send();
                    $this->reloadRecord();
                }),

            Action::make('refund')
                ->label(__('Request a refund'))
                ->icon('heroicon-o-receipt-refund')
                ->color('danger')
                ->visible(fn (): bool => $this->record()->status === DonationStatus::Completed
                    && $this->record()->transaction?->status->isSettled()
                    && $this->record()->transaction->refundableAmount()->isPositive()
                    && auth()->user()->can('donations.refund'))
                ->modalHeading(__('Request a refund'))
                ->modalDescription(fn (): string => __('Up to :amount can be returned. A second person approves it on the Refunds screen before anything is sent to the gateway.', [
                    'amount' => $this->record()->transaction->refundableAmount()->format(),
                ]))
                ->schema([
                    MoneyField::make('amount')
                        ->label(__('Amount to refund'))
                        ->required()
                        ->default(fn (): string => $this->record()->transaction->refundableAmount()->toMajorString()),
                    Textarea::make('reason')->label(__('Why'))->required()->rows(3),
                ])
                ->action(function (array $data): void {
                    $donation = $this->record();

                    try {
                        $refund = app(RefundService::class)->request(
                            $donation->transaction,
                            Money::ofMinor((int) $data['amount']),
                            (string) $data['reason'],
                            auth()->user(),
                        );
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    app(AuditLogger::class)->record('refund.requested', 'Refund of '.$refund->amount->format().' requested on '.$donation->reference, $refund, auth()->user(), ['amount' => $refund->amount->format(), 'donation' => $donation->reference]);

                    Notification::make()->title(__('Refund requested. It needs approval by somebody else before it is sent.'))->success()->send();
                    $this->reloadRecord();
                }),

            Action::make('note')
                ->label(__('Add a note'))
                ->icon('heroicon-o-pencil')
                ->color('gray')
                ->schema([
                    Textarea::make('note')->label(__('Note'))->required()->rows(3)->helperText(__('Internal. Appended with your name and the date.')),
                ])
                ->action(function (array $data): void {
                    $donation = $this->record();
                    $line = now()->format('Y-m-d H:i').' '.auth()->user()->name.': '.trim((string) $data['note']);

                    $donation->forceFill(['notes' => trim((string) $donation->notes."\n".$line)])->save();
                    app(AuditLogger::class)->record('donation.note_added', 'Note added to '.$donation->reference, $donation, auth()->user(), ['reference' => $donation->reference]);

                    $this->reloadRecord();
                }),
        ];
    }

    /**
     * The page state is serialised into the HTML, hidden entries included.
     * A hidden entry is not a withheld one, so the personal details are
     * removed from the state before it leaves the server.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! auth()->user()->can('donations.view_pii')) {
            unset($data['donor_email'], $data['donor_phone'], $data['consent_text'], $data['consent_ip'], $data['ip_address']);
        }

        return $data;
    }

    private function record(): Donation
    {
        /** @var Donation $record */
        $record = $this->getRecord();

        return $record;
    }

    private function reloadRecord(): void
    {
        $this->getRecord()->refresh();
        $this->fillForm();
    }
}
