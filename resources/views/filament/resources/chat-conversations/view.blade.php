{{--
    One live chat, from the office's side.

    The thread refreshes every four seconds; each refresh also marks the
    reader as online to the visitor's widget and the thread as read. Sending
    is a Livewire action, so the reply is on the visitor's screen within
    their next poll.
--}}
@php
    $conversation = $this->getRecord();
    $messages = $this->messages;
@endphp

<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
        <div class="fi-section flex flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="min-height: 28rem">
            <ol
                wire:poll.4s="seen"
                class="flex flex-1 flex-col gap-2 overflow-y-auto p-4 text-sm"
                aria-live="polite"
            >
                @forelse ($messages as $message)
                    @if ($message->sender === App\Models\ChatMessage::SENDER_SYSTEM)
                        <li class="self-center rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">{{ $message->body }}</li>
                    @elseif ($message->isFromStaff())
                        <li class="max-w-[85%] self-end rounded-xl rounded-br-sm bg-primary-600 px-3 py-2 text-white">
                            <span class="block text-[0.7rem] font-semibold opacity-80">{{ $message->author?->name ?? __('Office') }} · {{ $message->created_at?->format('H:i') }}</span>
                            <span class="whitespace-pre-wrap break-words">{{ $message->body }}</span>
                        </li>
                    @else
                        <li class="max-w-[85%] self-start rounded-xl rounded-bl-sm bg-gray-100 px-3 py-2 text-gray-950 dark:bg-white/10 dark:text-white">
                            <span class="block text-[0.7rem] font-semibold opacity-70">{{ $conversation->visitor_name }} · {{ $message->created_at?->format('H:i') }}</span>
                            <span class="whitespace-pre-wrap break-words">{{ $message->body }}</span>
                        </li>
                    @endif
                @empty
                    <li class="text-gray-500">{{ __('Nothing said yet.') }}</li>
                @endforelse
            </ol>

            @if ($conversation->isOpen())
                @can('reply', $conversation)
                    <form wire:submit="send" class="flex items-end gap-2 border-t border-gray-200 p-3 dark:border-white/10">
                        <label for="chat-reply" class="sr-only">{{ __('Reply') }}</label>
                        <textarea
                            id="chat-reply"
                            wire:model="reply"
                            rows="2"
                            required
                            maxlength="2000"
                            placeholder="{{ __('Type a reply — Enter sends') }}"
                            class="fi-input block w-full resize-none rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                            wire:keydown.enter.prevent="send"
                        ></textarea>
                        <x-filament::button type="submit">{{ __('Send') }}</x-filament::button>
                    </form>
                @endcan
            @else
                <p class="border-t border-gray-200 p-3 text-sm text-gray-500 dark:border-white/10">{{ __('This chat is closed.') }}</p>
            @endif
        </div>

        <aside class="fi-section space-y-3 rounded-xl bg-white p-4 text-sm shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Visitor') }}</p>
                <p class="font-semibold text-gray-950 dark:text-white">{{ $conversation->visitor_name }}</p>
                @if ($conversation->visitor_email)
                    <p><a href="mailto:{{ $conversation->visitor_email }}" class="text-primary-600 hover:underline">{{ $conversation->visitor_email }}</a></p>
                @else
                    <p class="text-gray-500">{{ __('No email given') }}</p>
                @endif
                @if ($conversation->user)
                    <p class="text-gray-500">{{ __('Signed in as a donor') }}</p>
                @endif
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Started') }}</p>
                <p>{{ $conversation->created_at?->format('j M Y, H:i') }}</p>
                @if ($conversation->page_url)
                    <p class="truncate text-gray-500" title="{{ $conversation->page_url }}">{{ __('from') }} {{ str($conversation->page_url)->after('://')->limit(40) }}</p>
                @endif
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('With') }}</p>
                <p>{{ $conversation->assignee?->name ?? __('Nobody yet') }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Status') }}</p>
                <p>{{ ucfirst($conversation->status) }}@if ($conversation->closed_at) · {{ $conversation->closed_at->format('j M, H:i') }}@endif</p>
            </div>
        </aside>
    </div>
</x-filament-panels::page>
