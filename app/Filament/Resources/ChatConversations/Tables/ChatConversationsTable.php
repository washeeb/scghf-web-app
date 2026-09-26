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
 *
 * A chat the assistant is still handling sorts BELOW one waiting for a
 * person, and says so. Nobody should have to open a conversation to find out
 * whether anybody is expected to do anything about it.
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

                return $query
                    ->with(['assignee', 'department'])
                    ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
                    // Waiting on a person before being answered by a machine.
                    ->orderByRaw("CASE WHEN handled_by = 'agent' THEN 1 ELSE 0 END")
                    ->orderBy('last_visitor_message_at');
            })
            ->columns([
                IconColumn::make('waiting')
                    ->label('')
                    // Not a bell for a chat the assistant has: the bell means
                    // "a person has not looked at this and should".
                    ->state(fn (ChatConversation $record): bool => $record->isOpen() && ! $record->isWithAgent() && $record->hasUnreadForStaff())
                    ->boolean()
                    ->trueIcon('heroicon-s-bell-alert')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (ChatConversation $record): ?string => $record->isOpen() && ! $record->isWithAgent() && $record->hasUnreadForStaff() ? __('Waiting for an answer') : null),

                TextColumn::make('visitor_name')
                    ->label(__('Visitor'))
                    ->searchable()
                    ->icon(fn (ChatConversation $record): ?string => $record->isOnWhatsapp() ? 'heroicon-o-device-phone-mobile' : null)
                    ->tooltip(fn (ChatConversation $record): ?string => $record->isOnWhatsapp() ? __('On WhatsApp') : null)
                    ->description(fn (ChatConversation $record): string => (string) ($record->isOnWhatsapp()
                        ? ($record->whatsapp_wa_id ?: __('WhatsApp'))
                        : ($record->visitor_email ?: __('no email')))),

                TextColumn::make('lastLine')
                    ->label(__('Last message'))
                    ->state(fn (ChatConversation $record): string => (string) str((string) $record->messages()->latest('id')->value('body'))->limit(80))
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === ChatConversation::STATUS_OPEN ? 'success' : 'gray'),

                TextColumn::make('handler')
                    ->label(__('With'))
                    ->state(fn (ChatConversation $record): string => match (true) {
                        $record->isWithAgent() => (string) setting('agent.name', __('Assistant')),
                        $record->assignee !== null => (string) $record->assignee->name,
                        default => __('Nobody yet'),
                    })
                    ->badge()
                    ->color(fn (ChatConversation $record): string => match (true) {
                        $record->isWithAgent() => 'info',
                        $record->assignee !== null => 'success',
                        default => 'warning',
                    })
                    // Why a person was needed, for the person about to take it.
                    ->description(fn (ChatConversation $record): ?string => $record->wasEscalated() && ! $record->isWithAgent()
                        ? trim(($record->department?->name ? $record->department->name.' — ' : '').(string) $record->escalation_reason, ' —')
                        : null),

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
                SelectFilter::make('handled_by')
                    ->label(__('Handled by'))
                    ->options([
                        ChatConversation::HANDLER_STAFF => __('A person'),
                        ChatConversation::HANDLER_AGENT => __('The assistant'),
                    ]),
                SelectFilter::make('channel')
                    ->label(__('Where from'))
                    ->options([
                        ChatConversation::CHANNEL_WEB => __('The website'),
                        ChatConversation::CHANNEL_WHATSAPP => __('WhatsApp'),
                    ]),
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
