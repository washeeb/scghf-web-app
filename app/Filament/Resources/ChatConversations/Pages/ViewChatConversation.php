<?php

declare(strict_types=1);

namespace App\Filament\Resources\ChatConversations\Pages;

use App\Chat\LiveChat;
use App\Filament\Resources\ChatConversations\ChatConversationResource;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

/**
 * One conversation, live.
 *
 * The thread polls itself every four seconds (`wire:poll` in the view) and
 * each poll marks the reader present, so the visitor's widget shows the
 * office as online for as long as this page is open. Sending is a Livewire
 * action — this is the admin panel, where Livewire already runs — and the
 * reply is on the visitor's screen within their next poll.
 */
class ViewChatConversation extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ChatConversationResource::class;

    protected string $view = 'filament.resources.chat-conversations.view';

    public string $reply = '';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(auth()->user()?->can('view', $this->record), 403);

        $this->seen();
    }

    /** The record, as what it is. */
    private function conversation(): ChatConversation
    {
        $record = $this->getRecord();

        if (! $record instanceof ChatConversation) {
            abort(404);
        }

        return $record;
    }

    public function getTitle(): string
    {
        return __('Chat with :name', ['name' => $this->conversation()->visitor_name]);
    }

    /** Every poll: mark presence and mark the thread read. */
    public function seen(): void
    {
        if ($user = auth()->user()) {
            app(LiveChat::class)->touchPresence($user);
        }

        $conversation = $this->conversation();

        if ($conversation->hasUnreadForStaff()) {
            $conversation->forceFill(['staff_seen_at' => now()])->saveQuietly();
        }
    }

    /** @return Collection<int, ChatMessage> */
    public function getMessagesProperty(): Collection
    {
        return ChatMessage::query()->where('chat_conversation_id', $this->conversation()->getKey())->orderBy('id')->with('author')->get();
    }

    public function send(): void
    {
        $conversation = $this->conversation();

        abort_unless(auth()->user()?->can('reply', $conversation), 403);

        $body = trim($this->reply);

        if ($body === '' || ! $conversation->isOpen()) {
            return;
        }

        app(LiveChat::class)->staffReply($conversation, auth()->user(), $body);

        $this->reply = '';
        $this->record = $conversation->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assign')
                ->label(__('Take this chat'))
                ->icon('heroicon-o-hand-raised')
                ->visible(fn (): bool => $this->conversation()->isOpen() && $this->conversation()->assigned_to !== auth()->id() && (auth()->user()?->can('reply', $this->conversation()) ?? false))
                ->action(function (): void {
                    app(LiveChat::class)->assign($this->conversation(), auth()->user());
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title(__('This chat is yours.'))->success()->send();
                }),

            Action::make('close')
                ->label(__('Close chat'))
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->conversation()->isOpen() && ((auth()->user()?->can('chat.manage') ?? false) || $this->conversation()->assigned_to === auth()->id()))
                ->requiresConfirmation()
                ->modalDescription(__('The visitor sees a closing line and, if they gave an email, receives the transcript.'))
                ->action(function (): void {
                    app(LiveChat::class)->close($this->conversation(), auth()->user());
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title(__('Chat closed.'))->success()->send();
                }),

            Action::make('reopen')
                ->label(__('Reopen'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => ! $this->conversation()->isOpen() && (auth()->user()?->can('chat.manage') ?? false))
                ->action(function (): void {
                    app(LiveChat::class)->reopen($this->conversation());
                    $this->record = $this->getRecord()->refresh();
                }),
        ];
    }
}
