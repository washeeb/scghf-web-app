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
    $assistant = (string) setting('agent.name', __('Assistant'));
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
                    @elseif ($message->isFromAgent())
                        {{-- Dashed, and never mistakable for a colleague's
                             reply. The small flag marks an answer as wrong;
                             it is the only route a bad answer has back to
                             anybody who can do something about it. --}}
                        <li class="group max-w-[85%] self-start rounded-xl rounded-bl-sm border border-dashed border-gray-300 bg-white px-3 py-2 text-gray-950 dark:border-white/20 dark:bg-transparent dark:text-white">
                            <span class="block text-[0.7rem] font-semibold opacity-70">
                                {{ $assistant }} · {{ $message->created_at?->format('H:i') }}
                                @if ($message->interaction?->flagged)
                                    <span class="ml-1 rounded bg-danger-100 px-1 text-danger-700 dark:bg-danger-400/20 dark:text-danger-300">{{ __('marked wrong') }}</span>
                                @endif
                            </span>
                            <span class="whitespace-pre-wrap break-words">{{ $message->body }}</span>
                            @if ($message->interaction && ! $message->interaction->flagged)
                                @can('reply', $conversation)
                                    <button
                                        type="button"
                                        wire:click="flagAnswer('{{ $message->getKey() }}')"
                                        wire:confirm="{{ __('Mark this answer as wrong, so it is reviewed?') }}"
                                        class="mt-1 block text-[0.7rem] text-gray-500 underline opacity-0 transition group-hover:opacity-100 focus:opacity-100"
                                    >{{ __('This answer was wrong') }}</button>
                                @endcan
                            @endif
                        </li>
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
                @if ($conversation->isWithAgent())
                    <p class="font-semibold text-info-600 dark:text-info-400">{{ $assistant }}</p>
                    <p class="text-gray-500">{{ trans_choice('{1}:count answer so far|[2,*]:count answers so far', $conversation->agent_replies, ['count' => $conversation->agent_replies]) }}</p>
                @else
                    <p>{{ $conversation->assignee?->name ?? __('Nobody yet') }}</p>
                @endif
            </div>

            @if ($conversation->wasEscalated() && ! $conversation->isWithAgent())
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Passed to a person') }}</p>
                    <p>{{ $conversation->escalated_at?->format('j M, H:i') }}</p>
                    <p class="text-gray-500">{{ $conversation->escalation_reason }}</p>
                    @if ($conversation->department)
                        <p class="text-gray-500">{{ __('Routed to :department', ['department' => $conversation->department->name]) }}</p>
                    @endif
                </div>
            @endif

            @if ($conversation->isOnWhatsapp())
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('WhatsApp') }}</p>
                    <p>{{ $conversation->whatsapp_wa_id }}</p>
                    @if ($conversation->whatsappWindowOpen())
                        <p class="text-gray-500">{{ __('Free replies until :time', ['time' => $conversation->whatsapp_window_expires_at?->format('j M, H:i')]) }}</p>
                    @else
                        {{-- Worth saying plainly: past the window a reply goes
                             as an approved template or not at all. --}}
                        <p class="text-warning-600 dark:text-warning-400">{{ __('Past Meta\'s 24-hour window — a reply goes as the approved template.') }}</p>
                    @endif
                </div>
            @endif
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Status') }}</p>
                <p>{{ ucfirst($conversation->status) }}@if ($conversation->closed_at) · {{ $conversation->closed_at->format('j M, H:i') }}@endif</p>
            </div>
        </aside>
    </div>
</x-filament-panels::page>
