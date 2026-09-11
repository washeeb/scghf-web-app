<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Shop\OrderNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

/**
 * Fulfilling an order.
 *
 * Each button is one recorded transition. "Dispatched" also tells the
 * customer — the `order.shipped` email and SMS have existed since Phase 3
 * with nothing that sent them — and asks for the courier, because "your order
 * has been dispatched" with no way to chase it is a message that generates a
 * phone call.
 *
 * A PAID order cannot be cancelled here. The money has to go back first, and
 * a refund is its own record with its own approval (Phase 9). What can be
 * cancelled is an unpaid one, which puts the stock back on the shelf.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->statusAction('processing', __('Being prepared'), OrderStatus::Processing, [OrderStatus::Paid], 'heroicon-o-cube'),
            $this->dispatchedAction(),
            $this->statusAction('delivered', __('Delivered'), OrderStatus::Delivered, [OrderStatus::Shipped, OrderStatus::Processing, OrderStatus::Paid], 'heroicon-o-check-circle', collectionOnly: false),
            $this->statusAction('collected', __('Collected'), OrderStatus::Collected, [OrderStatus::Paid, OrderStatus::Processing], 'heroicon-o-hand-raised', collectionOnly: true),
            $this->cancelAction(),
            $this->resendConfirmationAction(),
        ];
    }

    /** @param array<int, OrderStatus> $from */
    private function statusAction(string $name, string $label, OrderStatus $to, array $from, string $icon, ?bool $collectionOnly = null): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(function () use ($from, $collectionOnly): bool {
                /** @var Order $order */
                $order = $this->getRecord();

                if ($collectionOnly !== null && $order->is_pickup !== $collectionOnly) {
                    return false;
                }

                return in_array($order->status, $from, true);
            })
            ->requiresConfirmation()
            ->action(function () use ($to): void {
                $this->getRecord()->transitionTo($to, auth()->user());

                Notification::make()->title(__('Marked as :status.', ['status' => $to->label()]))->success()->send();

                $this->getRecord()->refresh();
                $this->fillForm();
            });
    }

    private function dispatchedAction(): Action
    {
        return Action::make('shipped')
            ->label(__('Dispatched'))
            ->icon('heroicon-o-truck')
            ->visible(function (): bool {
                /** @var Order $order */
                $order = $this->getRecord();

                return ! $order->is_pickup && in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing], true);
            })
            ->modalHeading(__('Mark as dispatched and tell the customer'))
            ->schema([
                TextInput::make('courier')
                    ->label(__('Courier'))
                    ->required()
                    ->maxLength(191)
                    ->helperText(__('Named in the email and the text message.')),

                TextInput::make('tracking')
                    ->label(__('Tracking reference'))
                    ->maxLength(191)
                    ->helperText(__('Optional.')),
            ])
            ->action(function (array $data): void {
                /** @var Order $order */
                $order = $this->getRecord();

                $order->transitionTo(OrderStatus::Shipped, auth()->user(), 'Dispatched with '.$data['courier'].'.');

                app(OrderNotifier::class)->shipped($order, (string) $data['courier'], $data['tracking'] ?: null);

                Notification::make()->title(__('Marked as dispatched. The customer has been told.'))->success()->send();

                $this->getRecord()->refresh();
                $this->fillForm();
            });
    }

    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel order'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => ! $this->getRecord()->status->isPaid() && $this->getRecord()->status !== OrderStatus::Cancelled)
            ->modalDescription(__('The items go back on the shelf. Nothing has been paid, so there is nothing to refund.'))
            ->schema([
                Textarea::make('reason')->label(__('Why'))->required()->rows(2),
            ])
            ->action(function (array $data): void {
                try {
                    $this->getRecord()->cancel((string) $data['reason'], auth()->user());
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Cancelled, and the stock released.'))->success()->send();

                $this->getRecord()->refresh();
                $this->fillForm();
            });
    }

    private function resendConfirmationAction(): Action
    {
        return Action::make('resend')
            ->label(__('Resend confirmation'))
            ->icon('heroicon-o-envelope')
            ->color('gray')
            ->visible(fn (): bool => $this->getRecord()->status->isPaid())
            ->requiresConfirmation()
            ->modalDescription(__('Queues the confirmation email again, with the invoice number. Use it when the customer says it never arrived.'))
            ->action(function (): void {
                /** @var Order $order */
                $order = $this->getRecord();

                app(OrderNotifier::class)->resend($order);

                Notification::make()->title(__('Queued.'))->success()->send();
            });
    }
}
