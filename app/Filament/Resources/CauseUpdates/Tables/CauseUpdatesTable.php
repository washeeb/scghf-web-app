<?php

declare(strict_types=1);

namespace App\Filament\Resources\CauseUpdates\Tables;

use App\Models\CauseUpdate;
use App\Programmes\CauseUpdateNotifier;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Appeal updates, and the action that makes them worth writing.
 *
 * ── Telling the donors is a separate, deliberate step ───────────────────────
 *
 * Publishing does not send anything. Sending is a button somebody presses, with
 * a confirmation naming how many people it will reach — because an email to
 * four hundred donors is not something to trigger by ticking a box on a form
 * while editing a typo.
 *
 * It goes only to the people who gave to THIS appeal and who consented to
 * updates. A foundation that mails its whole list about one project teaches the
 * list to ignore it.
 */
class CauseUpdatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('cause'))
            ->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Update'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (CauseUpdate $record): ?string => $record->cause?->title),

                TextColumn::make('published_at')
                    ->label(__('Published'))
                    ->dateTime('j M Y')
                    ->placeholder(__('Not yet'))
                    ->sortable(),

                IconColumn::make('is_published')->label(__('Shown'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('cause_id')->label(__('Appeal'))->relationship('cause', 'title'),
                TernaryFilter::make('is_published')->label(__('Shown on the site')),
            ])
            ->recordActions([
                self::notifyAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    /**
     * Email the people who gave to this appeal.
     *
     * Offered only for a published update: emailing a link to something a
     * visitor cannot see is the one mistake this action could make that a donor
     * would definitely notice.
     */
    private static function notifyAction(): Action
    {
        return Action::make('notify')
            ->label(__('Tell the donors'))
            ->icon('heroicon-o-envelope')
            ->visible(fn (CauseUpdate $record): bool => (bool) $record->is_published)
            ->requiresConfirmation()
            ->modalHeading(__('Email this update to the people who gave'))
            ->modalDescription(__(
                'It goes only to donors of this appeal who agreed to receive updates, one email each '
                .'however many times they gave. They are queued and sent gradually, because the host '
                .'caps how much mail can leave in an hour.'
            ))
            ->modalSubmitActionLabel(__('Send it'))
            ->action(function (CauseUpdate $record): void {
                $queued = app(CauseUpdateNotifier::class)->notify($record);

                Notification::make()
                    ->title($queued === 0 ? __('Nobody to tell') : __('Queued'))
                    ->body($queued === 0
                        ? __('No donor of this appeal has agreed to receive updates.')
                        : trans_choice(
                            '{1}Queued for one supporter.|[2,*]Queued for :count supporters.',
                            $queued,
                            ['count' => $queued],
                        ))
                    ->success()
                    ->send();
            });
    }
}
