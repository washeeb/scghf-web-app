<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentWebhookEvents\Tables;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\PaymentWebhookEvent;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every delivery the gateway made.
 *
 * ── A run of invalid signatures is the thing to look for ────────────────────
 *
 * One is a misconfigured secret. Twenty from the same address is somebody
 * probing the endpoint. Both are visible here and nowhere else.
 *
 * ── Replay is safe, and that is the whole design ────────────────────────────
 *
 * Reprocessing an event runs it through the same idempotent path as the
 * first delivery. A settled payment stays settled once; a stuck event — a
 * queue that died mid-job — is finished. Gated on `payments.replay_webhook`.
 */
class PaymentWebhookEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->label(__('Received'))->dateTime('j M Y, H:i:s')->sortable(),
                TextColumn::make('event_type')->label(__('Event'))->badge()->searchable(),
                TextColumn::make('gateway_reference')->label(__('Reference'))->fontFamily('mono')->searchable()->placeholder('—'),
                IconColumn::make('signature_valid')->label(__('Signature'))->boolean(),
                TextColumn::make('source_ip')->label(__('From'))->fontFamily('mono')->toggleable(),
                TextColumn::make('processed_at')->label(__('Processed'))->dateTime('j M Y, H:i:s')->placeholder(__('Not yet')),
                TextColumn::make('attempts')->label(__('Attempts'))->alignEnd()->toggleable(),
                TextColumn::make('processing_error')->label(__('Error'))->wrap()->limit(60)->color('danger')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('event_type')->label(__('Event'))->options(fn (): array => collect(config('payments.webhooks.handled_events', []))->mapWithKeys(fn ($e) => [$e => $e])->all()),
                Filter::make('invalid')->label(__('Invalid signature'))->query(fn (Builder $q) => $q->where('signature_valid', false)),
                Filter::make('unprocessed')->label(__('Verified but not processed'))->query(fn (Builder $q) => $q->where('signature_valid', true)->whereNull('processed_at')),
            ])
            ->recordActions([
                Action::make('replay')
                    ->label(__('Reprocess'))
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (PaymentWebhookEvent $e): bool => $e->signature_valid && auth()->user()->can('payments.replay_webhook'))
                    ->requiresConfirmation()
                    ->modalDescription(__('Runs the event through the same idempotent path as the first delivery. A settled payment stays settled once.'))
                    ->action(function (PaymentWebhookEvent $e): void {
                        $e->forceFill(['processed_at' => null])->save();
                        ProcessPaymentWebhook::dispatchSync($e->id);
                        Notification::make()->title(__('Reprocessed.'))->success()->send();
                    }),
                ViewAction::make(),
            ]);
    }
}
