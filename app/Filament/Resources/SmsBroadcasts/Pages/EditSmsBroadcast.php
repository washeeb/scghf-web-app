<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsBroadcasts\Pages;

use App\Communications\BroadcastSender;
use App\Filament\Resources\SmsBroadcasts\SmsBroadcastResource;
use App\Models\ScheduledMessage;
use App\Models\SmsBroadcast;
use App\Support\AuditLogger;
use App\ValueObjects\Money;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A broadcast's life: draft → approved by somebody else → queued.
 *
 * The cost is on the approval dialog, in cedis, next to the number of
 * people — the last thing read before the button. Queued means the
 * ordinary outbox now owns it; cancelling afterwards skips whatever has
 * not yet gone.
 */
class EditSmsBroadcast extends EditRecord
{
    protected static string $resource = SmsBroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('Approve'))
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => auth()->user()->can('newsletter.send') && ! $this->broadcast()->isApproved() && $this->broadcast()->status === SmsBroadcast::STATUS_DRAFT)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __(':count people, :segments segment(s) each, about :cost. You are confirming the wording and that these people may be texted.', [
                    'count' => $this->broadcast()->recipient_count,
                    'segments' => $this->broadcast()->segments,
                    'cost' => Money::ofMinor((int) $this->broadcast()->estimated_cost_minor)->format(),
                ]))
                ->action(function (): void {
                    try {
                        $this->broadcast()->approve(auth()->user());
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()->title(__('Approved.'))->success()->send();
                    $this->reload();
                }),

            Action::make('send')
                ->label(fn (): string => $this->broadcast()->scheduled_for?->isFuture() ? __('Queue for :when', ['when' => $this->broadcast()->scheduled_for->format('j M, H:i')]) : __('Send now'))
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn (): bool => auth()->user()->can('newsletter.send') && $this->broadcast()->isApproved() && $this->broadcast()->status === SmsBroadcast::STATUS_DRAFT)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => __('Queues one text per number into the outbox, which sends within the hourly allowance and outside quiet hours, checking the do-not-contact list again for each one. About :cost.', [
                    'cost' => Money::ofMinor((int) $this->broadcast()->estimated_cost_minor)->format(),
                ]))
                ->action(function (): void {
                    try {
                        $queued = app(BroadcastSender::class)->queue($this->broadcast());
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    app(AuditLogger::class)->record('sms.broadcast_sent', 'Broadcast "'.$this->broadcast()->title.'" queued to '.$queued.' numbers', $this->broadcast(), auth()->user(), ['recipients' => $queued]);
                    Notification::make()->title(__(':count texts queued.', ['count' => $queued]))->success()->send();
                    $this->reload();
                }),

            Action::make('cancel')
                ->label(__('Cancel'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->broadcast()->status !== SmsBroadcast::STATUS_CANCELLED)
                ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)])
                ->action(function (array $data): void {
                    $broadcast = $this->broadcast();

                    // Whatever the outbox has not yet sent is dropped.
                    ScheduledMessage::query()
                        ->where('related_type', $broadcast->getMorphClass())
                        ->where('related_id', $broadcast->getKey())
                        ->whereNotIn('status', [ScheduledMessage::STATUS_SENT, ScheduledMessage::STATUS_CANCELLED])
                        ->get()
                        ->each(fn (ScheduledMessage $m) => $m->cancel((string) $data['reason']));

                    $broadcast->forceFill(['status' => SmsBroadcast::STATUS_CANCELLED, 'cancel_reason' => $data['reason']])->save();
                    Notification::make()->title(__('Cancelled. What was already sent stays sent.'))->success()->send();
                    $this->reload();
                }),

            DeleteAction::make()->visible(fn (): bool => $this->broadcast()->status === SmsBroadcast::STATUS_DRAFT),
        ];
    }

    public function getSubheading(): ?string
    {
        $b = $this->broadcast();

        return implode(' · ', array_filter([
            __('Status: :status', ['status' => $b->status]),
            $b->approved_at ? __('approved by :who', ['who' => $b->approver?->name]) : __('not yet approved'),
            $b->queued_at ? __(':queued queued, :sent sent', ['queued' => $b->queued_count, 'sent' => $b->logs()->where('status', 'sent')->count()]) : null,
        ]));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return parent::handleRecordUpdate($record, $data);
        } catch (RuntimeException $e) {
            Notification::make()->title(__('Not saved'))->body($e->getMessage())->danger()->persistent()->send();
            $this->halt();
        }
    }

    private function reload(): void
    {
        $this->getRecord()->refresh();
        $this->fillForm();
    }

    private function broadcast(): SmsBroadcast
    {
        /** @var SmsBroadcast $record */
        $record = $this->getRecord();

        return $record;
    }
}
