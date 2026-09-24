<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * One order, read-only.
 *
 * Everything on it is a snapshot — the lines, the prices, the delivery
 * method — and nothing here is editable, because an order is what the customer
 * agreed to pay for at a price that was live then. The status changes through
 * the actions at the top of the page, each of which is a recorded transition.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The order'))->columns(3)->schema([
                TextEntry::make('reference')->label(__('Reference'))->fontFamily('mono')->copyable(),
                TextEntry::make('status')->label(__('Status'))->badge()->state(fn (Order $record): string => $record->status->label()),
                TextEntry::make('created_at')->label(__('Placed'))->dateTime('j M Y, H:i'),
                TextEntry::make('paid_at')->label(__('Paid'))->dateTime('j M Y, H:i')->placeholder(__('Not paid')),
                TextEntry::make('channel')->label(__('Paid by'))->placeholder('—'),
                TextEntry::make('paystack_reference')->label(__('Gateway reference'))->fontFamily('mono')->placeholder('—'),
                TextEntry::make('invoice.invoice_number')->label(__('Invoice'))->fontFamily('mono')->placeholder(__('Not issued')),
                TextEntry::make('coupon_code')->label(__('Code used'))->fontFamily('mono')->placeholder('—'),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
            ]),

            Section::make(__('Lines'))->schema([
                TextEntry::make('lines')
                    ->hiddenLabel()
                    ->state(fn (Order $record): HtmlString => self::lines($record)),
            ]),

            Grid::make(2)->schema([
                Section::make(__('Customer'))->schema([
                    TextEntry::make('customer_name')->label(__('Name')),
                    TextEntry::make('customer_email')->label(__('Email'))->copyable(),
                    TextEntry::make('customer_phone')->label(__('Phone'))->placeholder('—')->copyable(),
                ]),

                Section::make(fn (Order $record): string => $record->is_pickup ? __('Collection') : __('Delivery'))->schema([
                    TextEntry::make('shipping_method')->label(__('Method'))->placeholder('—'),
                    TextEntry::make('delivery_name')->label(__('For'))->visible(fn (Order $record): bool => ! $record->is_pickup),
                    TextEntry::make('delivery_phone')->label(__('Phone'))->placeholder('—')->visible(fn (Order $record): bool => ! $record->is_pickup),
                    TextEntry::make('address')
                        ->label(__('Address'))
                        ->visible(fn (Order $record): bool => ! $record->is_pickup)
                        ->state(fn (Order $record): string => collect([
                            $record->delivery_address, $record->delivery_area, $record->delivery_region,
                        ])->filter()->implode(', ')),
                    TextEntry::make('delivery_notes')->label(__('Notes from the customer'))->placeholder('—'),
                ]),
            ]),

            Section::make(__('Courier'))
                ->visible(fn (Order $record): bool => $record->delivery !== null)
                ->columns(3)
                ->schema([
                    TextEntry::make('delivery.courier.name')->label(__('With'))->placeholder('—'),
                    TextEntry::make('delivery.status')->label(__('Delivery'))->badge()->state(fn (Order $record): string => (string) $record->delivery?->label()),
                    TextEntry::make('delivery.attempts')->label(__('Attempts')),
                    TextEntry::make('delivery.assigned_at')->label(__('Assigned'))->dateTime('j M, H:i')->placeholder('—'),
                    TextEntry::make('delivery.picked_up_at')->label(__('Picked up'))->dateTime('j M, H:i')->placeholder('—'),
                    TextEntry::make('delivery.delivered_at')->label(__('Delivered'))->dateTime('j M, H:i')->placeholder('—'),
                    TextEntry::make('delivery.recipient_name')->label(__('Received by'))->placeholder('—'),
                    TextEntry::make('delivery.proof_note')->label(__('Courier\'s note'))->placeholder('—')->columnSpan(2),
                    TextEntry::make('delivery.failure_reason')->label(__('Could not deliver'))->placeholder('—')->columnSpan(3)->visible(fn (Order $record): bool => filled($record->delivery?->failure_reason)),
                    TextEntry::make('proof')
                        ->label(__('Proof'))
                        ->visible(fn (Order $record): bool => (bool) ($record->delivery?->hasProofPhoto() || $record->delivery?->proofMapUrl()))
                        ->state(fn (Order $record): HtmlString => new HtmlString(collect([
                            $record->delivery?->hasProofPhoto() ? '<a class="text-primary-600 underline" href="'.e(route('deliveries.proof', $record->delivery)).'" target="_blank" rel="noopener">'.e(__('Photograph')).'</a>' : null,
                            $record->delivery?->proofMapUrl() ? '<a class="text-primary-600 underline" href="'.e($record->delivery->proofMapUrl()).'" target="_blank" rel="noopener">'.e(__('Where the phone was')).'</a>' : null,
                        ])->filter()->implode(' · ')))
                        ->columnSpan(3),
                ]),

            Section::make(__('History'))->collapsible()->schema([
                TextEntry::make('history')
                    ->hiddenLabel()
                    ->state(fn (Order $record): HtmlString => self::history($record)),
            ]),
        ]);
    }

    private static function lines(Order $record): HtmlString
    {
        $rows = $record->items->map(fn (OrderItem $item): string => sprintf(
            '<tr><td class="py-1 pr-4">%d ×</td><td class="py-1 pr-4">%s%s<br><span class="text-xs text-gray-500">%s</span></td><td class="py-1 text-right">%s</td></tr>',
            $item->quantity,
            e($item->product_name),
            $item->variant_name ? ' — '.e($item->variant_name) : '',
            e($item->sku),
            e($item->line_total->format()),
        ))->implode('');

        $totals = sprintf(
            '<tr><td colspan="2" class="pt-3 text-right">%s</td><td class="pt-3 text-right">%s</td></tr>%s'
            .'<tr><td colspan="2" class="text-right">%s</td><td class="text-right">%s</td></tr>'
            .'<tr class="font-semibold"><td colspan="2" class="text-right">%s</td><td class="text-right">%s</td></tr>',
            e(__('Subtotal')),
            e($record->subtotal->format()),
            $record->discount->isPositive()
                ? sprintf('<tr><td colspan="2" class="text-right">%s</td><td class="text-right">−%s</td></tr>', e(__('Discount')), e($record->discount->format()))
                : '',
            e($record->is_pickup ? __('Collection') : __('Delivery')),
            e($record->shipping->isZero() ? __('free') : $record->shipping->format()),
            e(__('Total')),
            e($record->total->format()),
        );

        return new HtmlString('<table class="w-full text-sm"><tbody>'.$rows.$totals.'</tbody></table>');
    }

    private static function history(Order $record): HtmlString
    {
        $rows = $record->history()
            ->with('changedBy')
            ->orderBy('created_at')
            ->get()
            ->map(fn (OrderStatusHistory $entry): string => sprintf(
                '<li class="py-1"><span class="text-xs text-gray-500">%s</span> — <strong>%s</strong>%s%s</li>',
                e($entry->created_at?->format('j M Y, H:i') ?? ''),
                e($entry->to_status instanceof \BackedEnum ? $entry->to_status->value : (string) $entry->to_status),
                $entry->note ? ' · '.e($entry->note) : '',
                $entry->changedBy ? ' · '.e($entry->changedBy->name) : ' · '.e(__('system')),
            ))
            ->implode('');

        return new HtmlString('<ul class="text-sm">'.$rows.'</ul>');
    }
}
