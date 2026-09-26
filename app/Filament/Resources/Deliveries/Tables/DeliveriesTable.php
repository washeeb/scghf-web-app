<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deliveries\Tables;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Delivery;
use App\Models\User;
use App\Shop\CourierService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class DeliveriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['order', 'courier'])
                ->orderByRaw("CASE status WHEN 'failed' THEN 0 WHEN 'out_for_delivery' THEN 1 WHEN 'picked_up' THEN 2 WHEN 'assigned' THEN 3 ELSE 4 END")
                ->orderByDesc('assigned_at'))
            ->columns([
                TextColumn::make('order.reference')
                    ->label(__('Order'))
                    ->searchable()
                    ->url(fn (Delivery $record): ?string => $record->order ? OrderResource::getUrl('view', ['record' => $record->order]) : null)
                    ->description(fn (Delivery $record): string => (string) ($record->order?->delivery_name ?: $record->order?->customer_name)),

                TextColumn::make('order.delivery_area')
                    ->label(__('Where'))
                    ->state(fn (Delivery $record): string => collect([$record->order?->delivery_area, $record->order?->delivery_city])->filter()->implode(', ') ?: '—'),

                TextColumn::make('courier.name')->label(__('Courier'))->placeholder('—'),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, Delivery $record): string => $record->label())
                    ->color(fn (string $state): string => match ($state) {
                        Delivery::STATUS_DELIVERED => 'success',
                        Delivery::STATUS_FAILED => 'danger',
                        Delivery::STATUS_OUT_FOR_DELIVERY => 'info',
                        Delivery::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('attempts')->label(__('Tries'))->alignCenter(),
                TextColumn::make('assigned_at')->label(__('Assigned'))->since()->sortable(),
                TextColumn::make('delivered_at')->label(__('Delivered'))->dateTime('j M, H:i')->placeholder('—')->sortable(),
                TextColumn::make('recipient_name')->label(__('Received by'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('failure_reason')->label(__('Reason'))->wrap()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_map(fn (string $l): string => __($l), Delivery::STATUS_LABELS)),
                SelectFilter::make('courier_id')->label(__('Courier'))->relationship('courier', 'name'),
            ])
            ->recordActions([
                Action::make('reassign')
                    ->label(__('Reassign'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Delivery $record): bool => $record->isActive() && (auth()->user()?->can('deliveries.assign') ?? false))
                    ->schema([
                        Select::make('courier_id')
                            ->label(__('Courier'))
                            ->options(fn (): array => User::query()->permission('deliveries.courier')->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        Textarea::make('notes')->label(__('Note for the courier'))->rows(2)->maxLength(500),
                    ])
                    ->action(function (Delivery $record, array $data): void {
                        try {
                            app(CourierService::class)->assign($record->order, User::query()->findOrFail($data['courier_id']), auth()->user(), $data['notes'] ?? null);
                            Notification::make()->title(__('Reassigned.'))->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('cancel')
                    ->label(__('Cancel delivery'))
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (Delivery $record): bool => $record->isActive() && (auth()->user()?->can('deliveries.assign') ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(__('The order keeps its status; the courier no longer sees it. Assign it again from the order when it is ready.'))
                    ->action(fn (Delivery $record) => app(CourierService::class)->cancel($record, auth()->user())),
            ])
            ->emptyStateHeading(__('No deliveries yet'))
            ->emptyStateDescription(__('Open a paid order and choose "Assign a courier".'));
    }
}
