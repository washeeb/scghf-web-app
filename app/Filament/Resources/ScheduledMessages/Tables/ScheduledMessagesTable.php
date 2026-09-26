<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScheduledMessages\Tables;

use App\Communications\SendThrottle;
use App\Models\ScheduledMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The outbox: what is waiting, what went, what did not and why.
 *
 * The heading says what the throttle allows this hour, so "why is nothing
 * going out" is answered before it is asked. Cancelling needs
 * `messages.cancel`; a campaign mid-send can be stopped from here one
 * message at a time, which is why it is its own permission.
 */
class ScheduledMessagesTable
{
    public static function configure(Table $table): Table
    {
        $throttle = app(SendThrottle::class);

        return $table
            ->heading(__('Outbox — email: :mail · SMS: :sms', ['mail' => $throttle->explain('mail'), 'sms' => $throttle->explain('sms')]))
            ->defaultSort('send_after', 'desc')
            ->columns([
                TextColumn::make('channel')->label(__('Via'))->badge()->color('gray'),
                TextColumn::make('template_key')->label(__('Template'))->fontFamily('mono')->searchable(),
                TextColumn::make('to_address')->label(__('To'))->searchable()->copyable()
                    ->description(fn (ScheduledMessage $r): ?string => $r->to_name),
                TextColumn::make('category')->label(__('Category'))->badge()->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (string $state): string => match ($state) {
                    ScheduledMessage::STATUS_SENT => 'success',
                    ScheduledMessage::STATUS_PENDING, ScheduledMessage::STATUS_CLAIMED => 'warning',
                    ScheduledMessage::STATUS_FAILED, ScheduledMessage::STATUS_SUPPRESSED => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('send_after')->label(__('Send from'))->dateTime('j M, H:i')->sortable(),
                TextColumn::make('attempts')->label(__('Tries'))->alignEnd()->toggleable(),
                TextColumn::make('last_error')->label(__('Last error'))->limit(80)->wrap()->placeholder('—')->toggleable(),
                TextColumn::make('sent_at')->label(__('Sent'))->dateTime('j M, H:i')->placeholder('—')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    ScheduledMessage::STATUS_PENDING => __('Waiting'),
                    ScheduledMessage::STATUS_CLAIMED => __('Sending'),
                    ScheduledMessage::STATUS_SENT => __('Sent'),
                    ScheduledMessage::STATUS_FAILED => __('Failed'),
                    ScheduledMessage::STATUS_SUPPRESSED => __('Refused by the do-not-contact list'),
                    ScheduledMessage::STATUS_EXPIRED => __('Expired unsent'),
                    ScheduledMessage::STATUS_CANCELLED => __('Cancelled'),
                ]),
                SelectFilter::make('channel')->label(__('Via'))->options([ScheduledMessage::CHANNEL_EMAIL => __('Email'), ScheduledMessage::CHANNEL_SMS => __('SMS')]),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ScheduledMessage $r): bool => in_array($r->status, [ScheduledMessage::STATUS_PENDING, ScheduledMessage::STATUS_FAILED], true) && auth()->user()->can('messages.cancel'))
                    ->schema([Textarea::make('reason')->label(__('Why'))->required()->rows(2)])
                    ->action(function (ScheduledMessage $r, array $data): void {
                        $r->cancel((string) $data['reason']);
                        Notification::make()->title(__('Cancelled.'))->success()->send();
                    }),
            ]);
    }
}
