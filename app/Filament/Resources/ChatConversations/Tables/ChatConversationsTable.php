<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatConversations\Tables;

use App\Chat\LiveChat;
use App\Models\ChatConversation;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The chat inbox.
 *
 * Open conversations first, and among them the one that has waited longest
 * for an answer at the top — the same rule as the contact inbox. The list
 * refreshes itself every ten seconds and, in doing so, keeps whoever has it
 * open marked as online to the widget.
 */
class ChatConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->modifyQueryUsing(function (Builder $query): Builder {
                if ($user = auth()->user()) {
                    app(LiveChat::class)->touchPresence($user);
                }

                return $query->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderBy('last_visitor_message_at');
            })
            ->columns([
                IconColumn::make('waiting')
                    ->label('')
                    ->state(fn (ChatConversation $record): bool => $record->isOpen() && $record->hasUnreadForStaff())
                    ->boolean()
                    ->trueIcon('heroicon-s-bell-alert')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (ChatConversation $record): ?string => $record->isOpen() && $record->hasUnreadForStaff() ? __('Waiting for an answer') : null),

                TextColumn::make('visitor_name')
                    ->label(__('Visitor'))
                    ->searchable()
                    ->description(fn (ChatConversation $record): string => (string) ($record->visitor_email ?: __('no email'))),

                TextColumn::make('lastLine')
                    ->label(__('Last message'))
                    ->state(fn (ChatConversation $record): string => (string) str((string) $record->messages()->latest('id')->value('body'))->limit(80))
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === ChatConversation::STATUS_OPEN ? 'success' : 'gray'),

                TextColumn::make('assignee.name')
                    ->label(__('With'))
                    ->placeholder(__('Nobody yet')),

                TextColumn::make('last_message_at')
                    ->label(__('Active'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    ChatConversation::STATUS_OPEN => __('Open'),
                    ChatConversation::STATUS_CLOSED => __('Closed'),
                ])->default(ChatConversation::STATUS_OPEN),
                TernaryFilter::make('mine')
                    ->label(__('Mine'))
                    ->queries(
                        true: fn (Builder $q) => $q->where('assigned_to', auth()->id()),
                        false: fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('assigned_to')->orWhere('assigned_to', '!=', auth()->id())),
                    ),
            ])
            ->recordActions([
                ViewAction::make()->label(__('Open')),
                Action::make('close')
                    ->label(__('Close'))
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (ChatConversation $record): bool => $record->isOpen() && (auth()->user()?->can('chat.manage') || $record->assigned_to === auth()->id()))
                    ->requiresConfirmation()
                    ->action(fn (ChatConversation $record) => app(LiveChat::class)->close($record, auth()->user())),
            ])
            ->emptyStateHeading(__('No chats yet'))
            ->emptyStateDescription(__('When a visitor starts a chat from the site it appears here. Keep this page open to show as online.'));
    }
}
