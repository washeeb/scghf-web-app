<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Payments\RecurringGivingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

/**
 * What staff may do to a standing gift: pause it, resume it, stop it with a
 * reason, or charge it now. Changing the amount is the donor's, not ours —
 * a gift is what the donor committed to, and staff raising it is exactly the
 * thing a donor would rightly complain about.
 */
class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('chargeNow')
                ->label(__('Charge now'))
                ->icon('heroicon-o-bolt')
                ->visible(fn (): bool => $this->sub()->status->isChargeable() && auth()->user()->can('subscriptions.manage'))
                ->requiresConfirmation()
                ->modalDescription(__('Takes this cycle\'s gift now rather than on the scheduled date. Use it for a gift whose date was missed; a second charge in the same cycle is refused.'))
                ->action(function (): void {
                    $charge = app(RecurringGivingService::class)->chargeOne($this->sub());
                    Notification::make()->title(__('Charge :status', ['status' => $charge->status]))->info()->send();
                    $this->reloadRecord();
                }),

            Action::make('pause')
                ->label(__('Pause'))
                ->icon('heroicon-o-pause')
                ->visible(fn (): bool => in_array($this->sub()->status, [SubscriptionStatus::Active, SubscriptionStatus::Failing], true) && auth()->user()->can('subscriptions.manage'))
                ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)])
                ->action(function (array $data): void {
                    $this->sub()->pause((string) $data['reason']);
                    $this->reloadRecord();
                }),

            Action::make('resume')
                ->label(__('Resume'))
                ->icon('heroicon-o-play')
                ->visible(fn (): bool => $this->sub()->status === SubscriptionStatus::Paused && auth()->user()->can('subscriptions.manage'))
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        $this->sub()->resume();
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->reloadRecord();
                }),

            Action::make('cancel')
                ->label(__('Stop'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => ! $this->sub()->status->isFinished() && auth()->user()->can('subscriptions.manage'))
                ->modalDescription(__('Stops the gift for good. The donor set up a commitment; stopping it on their behalf is something they asked for, and the reason should say so.'))
                ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)])
                ->action(function (array $data): void {
                    $this->sub()->cancel((string) $data['reason']);
                    $this->reloadRecord();
                }),
        ];
    }

    private function sub(): Subscription
    {
        /** @var Subscription $record */
        $record = $this->getRecord();

        return $record;
    }

    private function reloadRecord(): void
    {
        $this->getRecord()->refresh();
        $this->fillForm();
    }
}
