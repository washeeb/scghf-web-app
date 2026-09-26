<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\MoneyField;
use App\Models\Order;
use App\Models\User;
use App\Payments\RefundService;
use App\Shop\CourierService;
use App\Shop\OrderDocuments;
use App\Shop\OrderNotifier;
use App\Support\AuditLogger;
use App\ValueObjects\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            $this->statusAction('packed', __('Packed'), OrderStatus::Packed, [OrderStatus::Paid, OrderStatus::Processing], 'heroicon-o-archive-box'),
            $this->assignCourierAction(),
            $this->dispatchedAction(),
            $this->statusAction('out_for_delivery', __('Out for delivery'), OrderStatus::OutForDelivery, [OrderStatus::Shipped], 'heroicon-o-map-pin', collectionOnly: false),
            $this->statusAction('delivered', __('Delivered'), OrderStatus::Delivered, [OrderStatus::OutForDelivery, OrderStatus::Shipped, OrderStatus::Packed, OrderStatus::Processing, OrderStatus::Paid], 'heroicon-o-check-circle', collectionOnly: false),
            $this->statusAction('collected', __('Collected'), OrderStatus::Collected, [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Packed], 'heroicon-o-hand-raised', collectionOnly: true),
            $this->statusAction('completed', __('Completed'), OrderStatus::Completed, [OrderStatus::Delivered, OrderStatus::Collected], 'heroicon-o-flag'),
            $this->packingSlipAction(),
            $this->invoiceAction(),
            $this->refundAction(),
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

    /**
     * Hand the order to a courier, who confirms each step from their phone.
     * The Dispatched button stays for a courier company that is not on the
     * system; this is for the foundation's own riders and agents.
     */
    private function assignCourierAction(): Action
    {
        return Action::make('assignCourier')
            ->label(function (): string {
                /** @var Order $order */
                $order = $this->getRecord();

                return $order->delivery ? __('Reassign courier') : __('Assign a courier');
            })
            ->icon('heroicon-o-truck')
            ->visible(function (): bool {
                /** @var Order $order */
                $order = $this->getRecord();

                return (auth()->user()?->can('deliveries.assign') ?? false)
                    && ! $order->is_pickup
                    && $order->status->isPaid()
                    && ! in_array($order->status, [OrderStatus::Delivered, OrderStatus::Collected, OrderStatus::Completed, OrderStatus::Refunded], true);
            })
            ->schema([
                Select::make('courier_id')
                    ->label(__('Courier'))
                    ->options(fn (): array => User::query()->permission('deliveries.courier')->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->helperText(__('Anybody with the Courier role. Add one under Staff accounts.')),
                Textarea::make('notes')
                    ->label(__('Note for the courier'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                /** @var Order $order */
                $order = $this->getRecord();
                $courier = User::query()->findOrFail($data['courier_id']);

                try {
                    app(CourierService::class)->assign($order, $courier, auth()->user(), $data['notes'] ?? null);
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('Assigned to :name. They have been emailed.', ['name' => $courier->name]))->success()->send();

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

                return ! $order->is_pickup && in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Packed], true);
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

    private function packingSlipAction(): Action
    {
        return Action::make('packingSlip')
            ->label(__('Packing slip'))
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->visible(fn (): bool => $this->getRecord()->status->isPaid() && $this->getRecord()->requiresDelivery())
            ->action(function (): StreamedResponse {
                /** @var Order $order */
                $order = $this->getRecord();
                $documents = app(OrderDocuments::class);

                app(AuditLogger::class)->recordExport('packing_slips.printed', 'packing slip', 1, auth()->user(), ['orders' => [$order->reference]]);

                return response()->streamDownload(
                    fn () => print ($documents->packingSlips([$order])),
                    $documents->packingSlipFilename($order),
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    private function invoiceAction(): Action
    {
        return Action::make('invoice')
            ->label(__('Invoice PDF'))
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->visible(fn (): bool => $this->getRecord()->invoice !== null)
            ->action(function (): StreamedResponse {
                /** @var Order $order */
                $order = $this->getRecord();
                $documents = app(OrderDocuments::class);

                app(AuditLogger::class)->recordExport('invoice.downloaded', 'shop invoice', 1, auth()->user(), ['invoice' => $order->invoice->invoice_number]);

                return response()->streamDownload(
                    fn () => print ($documents->renderInvoice($order->invoice)),
                    $documents->invoiceFilename($order->invoice),
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    /**
     * A refund is requested here and approved by somebody else on the
     * Refunds screen — the same two-person rule as a donation. When the
     * gateway confirms a full refund, the goods go back on the shelf.
     */
    private function refundAction(): Action
    {
        return Action::make('refund')
            ->label(__('Request a refund'))
            ->icon('heroicon-o-receipt-refund')
            ->color('danger')
            ->visible(fn (): bool => $this->getRecord()->status->isPaid()
                && $this->getRecord()->status !== OrderStatus::Refunded
                && $this->getRecord()->transaction?->status->isSettled()
                && $this->getRecord()->transaction->refundableAmount()->isPositive()
                && auth()->user()->can('orders.refund_request'))
            ->modalHeading(__('Request a refund'))
            ->modalDescription(fn (): string => __('Up to :amount can be returned. A second person approves it on the Refunds screen before anything is sent to the gateway. A full refund puts the goods back in stock when the gateway confirms it.', [
                'amount' => $this->getRecord()->transaction->refundableAmount()->format(),
            ]))
            ->schema([
                MoneyField::make('amount')
                    ->label(__('Amount to refund'))
                    ->required()
                    ->default(fn (): string => $this->getRecord()->transaction->refundableAmount()->toMajorString()),
                Textarea::make('reason')->label(__('Why'))->required()->rows(3),
            ])
            ->action(function (array $data): void {
                /** @var Order $order */
                $order = $this->getRecord();

                try {
                    $refund = app(RefundService::class)->request(
                        $order->transaction,
                        Money::ofMinor((int) $data['amount']),
                        (string) $data['reason'],
                        auth()->user(),
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                app(AuditLogger::class)->record('refund.requested', 'Refund of '.$refund->amount->format().' requested on order '.$order->reference, $refund, auth()->user(), ['amount' => $refund->amount->format(), 'order' => $order->reference]);

                Notification::make()->title(__('Refund requested. It needs approval by somebody else before it is sent.'))->success()->send();
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
