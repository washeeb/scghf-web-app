<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Filament\Support\ExportAction;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The orders.
 *
 * ── Sorted by what needs doing ──────────────────────────────────────────────
 *
 * Newest first, and the default filter is "to fulfil" — paid and not yet
 * shipped or collected — because that is the list somebody opens this screen
 * to work through. Unpaid orders are a separate view: they are customers who
 * may still come back, not work.
 *
 * ── Personal data leaves with the export ────────────────────────────────────
 *
 * The CSV carries names, addresses and phone numbers. It is audited like every
 * other export and it carries no more than the packing list needs.
 */
class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('items'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label(__('Order'))
                    ->searchable()
                    ->fontFamily('mono')
                    ->description(fn (Order $record): string => $record->created_at->format('j M Y, H:i')),

                TextColumn::make('customer_name')
                    ->label(__('Customer'))
                    ->searchable(['customer_name', 'customer_email', 'customer_phone'])
                    ->description(fn (Order $record): string => $record->customer_email),

                TextColumn::make('items')
                    ->label(__('Items'))
                    ->state(fn (Order $record): string => trans_choice(
                        '{1}:count item|[2,*]:count items',
                        $record->items->sum('quantity'),
                        ['count' => $record->items->sum('quantity')],
                    ))
                    ->description(fn (Order $record): string => $record->is_pickup
                        ? __('Collection')
                        : (string) $record->delivery_region),

                TextColumn::make('total')
                    ->label(__('Total'))
                    ->alignEnd()
                    ->state(fn (Order $record): string => $record->total->format())
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('total_minor', $direction)),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->state(fn (Order $record): string => $record->status->label())
                    ->color(fn (Order $record): string => match ($record->status) {
                        OrderStatus::Pending => 'gray',
                        OrderStatus::Paid, OrderStatus::Processing => 'warning',
                        OrderStatus::Shipped => 'info',
                        OrderStatus::Delivered, OrderStatus::Collected => 'success',
                        OrderStatus::Cancelled, OrderStatus::Refunded => 'gray',
                        OrderStatus::NeedsReview => 'danger',
                    }),
            ])
            ->filters([
                Filter::make('to_fulfil')
                    ->label(__('To fulfil'))
                    ->default()
                    ->query(fn (Builder $query) => $query->whereIn('status', [
                        OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Shipped->value,
                    ])),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(OrderStatus::options()),
                Filter::make('needs_review')
                    ->label(__('Needs review'))
                    ->query(fn (Builder $query) => $query->where('status', OrderStatus::NeedsReview->value)),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('orders'), [
                    'Order' => 'reference',
                    'Placed' => fn (Order $record) => $record->created_at->format('Y-m-d H:i'),
                    'Status' => fn (Order $record) => $record->status->label(),
                    'Customer' => 'customer_name',
                    'Email' => 'customer_email',
                    'Phone' => 'customer_phone',
                    'Items' => fn (Order $record) => $record->items
                        ->map(fn ($item) => $item->quantity.' × '.$item->sku)
                        ->implode('; '),
                    'Delivery' => fn (Order $record) => $record->is_pickup
                        ? 'Collection'
                        : collect([$record->delivery_address, $record->delivery_area, $record->delivery_region])->filter()->implode(', '),
                    'Total' => fn (Order $record) => $record->total->format(),
                ], ['items']),
            ]);
    }
}
