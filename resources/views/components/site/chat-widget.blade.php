{{--
    The live-chat widget: a launcher in the corner, a panel above it.

    ── Rendered by the server, driven by chat.js, no framework ────────────────

    The markup and every word come from here (Settings → Live chat), so the
    page has the widget before any script runs and an editor can change the
    wording without a deploy. chat.js only moves between the three states —
    closed, the pre-chat form, the conversation — and talks to the four JSON
    endpoints. Plain script rather than a component framework because the
    public site enforces a strict Content-Security-Policy (no `unsafe-eval`)
    and because the widget must cost next to nothing on a low-end phone.

    ── Honest about who is there ───────────────────────────────────────────────

    The status line says "online" only while a member of staff has the chat
    inbox open (LiveChat::staffOnline). Otherwise it says the office is away
    and shows the away message — the visitor can still write, and the office
    is emailed, but nobody is told a person is waiting when nobody is.

    The same principle covers the assistant: when it is answering, the header
    says so by name, the disclosure is the first line of the conversation, and
    "talk to a person" is a button on screen for as long as it is there. A
    visitor should never have to work out which they are talking to.
--}}
@php
    $chat = app(App\Chat\LiveChat::class);
@endphp

{{-- Not on the courier's pages: a rider at a door has no use for it, and it sits on the button they need. --}}
@if ($chat->enabled() && ! request()->routeIs('courier.*'))
    @php
        $position = setting('chat.position', 'right') === 'left' ? 'left-4 sm:left-6' : 'right-4 sm:right-6';
        $user = auth()->user();
        $labels = [
            'title' => setting('chat.title', __('Chat with us')),
            'button' => setting('chat.button_label', __('Chat')),
            'online' => setting('chat.online_label', __('We are online')),
            'away' => setting('chat.away_label', __('We are away right now')),
            'greeting' => setting('chat.greeting', __('Hello! How can we help?')),
            'offline' => setting('chat.offline_message', __('Leave a message and we will reply by email.')),
            'hours' => setting('chat.hours'),
            'name' => setting('chat.name_label', __('Your name')),
            'email' => setting('chat.email_label', __('Email')),
            'message' => setting('chat.message_placeholder', __('Type a message…')),
            'start' => setting('chat.start_label', __('Start chat')),
            'send' => setting('chat.send_label', __('Send')),
            'end' => setting('chat.end_label', __('End chat')),
            'closed' => setting('chat.closed_line', __('This chat has ended. Start a new one any time.')),
            'newChat' => setting('chat.new_chat_label', __('New chat')),
            'close' => __('Close'),
            'agent' => setting('agent.name', __('Assistant')),
            'human' => setting('agent.human_label', __('Talk to a person')),
            'thinking' => setting('agent.thinking_label', __('Typing…')),
        ];
        // For chat.js: JSON in a non-executing script tag (no nonce needed).
        $labelsJson = json_encode([
            'online' => $labels['online'],
            'away' => $labels['away'],
            'you' => __('You'),
            'office' => setting('general.short_name', config('app.name')),
            'failed' => __('That did not send. Check your connection and try again.'),
            'agent' => $labels['agent'],
            // "Hope is answering — talk to a person"; the second half is the
            // button beside it.
            'agentStatus' => __(':name is answering', ['name' => $labels['agent']]),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    @endphp

    <div
        data-chat
        data-chat-start-url="{{ route('chat.start') }}"
        data-chat-base-url="{{ url('/chat') }}"
        data-chat-csrf="{{ csrf_token() }}"
        data-chat-require-email="{{ (bool) setting('chat.require_email', true) && ! $user ? '1' : '0' }}"
        data-chat-user-name="{{ $user?->name }}"
        data-chat-user-email="{{ $user?->email }}"
        class="fixed bottom-4 z-40 flex flex-col items-end gap-3 sm:bottom-6 {{ $position }}"
    >
        {{-- The panel. Hidden until opened; `hidden` is the attribute, so no script means no panel and no broken launcher. --}}
        <section
            data-chat-panel
            hidden
            role="dialog"
            aria-labelledby="chat-title"
            class="flex w-[calc(100vw-2rem)] max-w-sm flex-col overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border)] bg-[var(--bg)] text-[var(--text-primary)] shadow-[var(--shadow-lg)]"
            style="height: min(32rem, calc(100vh - 7rem))"
        >
            <header class="flex items-center gap-3 bg-[var(--brand-primary)] px-4 py-3 text-[var(--text-on-brand)]">
                <div class="min-w-0 flex-1">
                    <h2 id="chat-title" class="font-heading text-base font-semibold">{{ $labels['title'] }}</h2>
                    <p class="flex items-center gap-1.5 text-xs opacity-90">
                        <span data-chat-dot class="inline-block size-2 rounded-full bg-white/50" aria-hidden="true"></span>
                        <span data-chat-status>{{ $labels['away'] }}</span>
                    </p>
                </div>
                <button type="button" data-chat-end hidden class="rounded-full px-2 py-1 text-xs hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">{{ $labels['end'] }}</button>
                <button type="button" data-chat-close class="rounded-full p-1.5 hover:bg-white/15 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white" aria-label="{{ $labels['close'] }}">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </header>

            {{-- The pre-chat form. --}}
            <form data-chat-form class="flex flex-1 flex-col gap-3 overflow-y-auto p-4">
                <p class="text-sm">{{ $labels['greeting'] }}</p>
                <p data-chat-away-note hidden class="rounded-[var(--radius-md)] bg-[var(--surface-sunken)] p-3 text-sm text-[var(--text-secondary)]">
                    {{ $labels['offline'] }}
                    @if ($labels['hours'])
                        <span class="block text-xs text-[var(--text-muted)]">{{ $labels['hours'] }}</span>
                    @endif
                </p>

                {{-- A field no person sees; a bot fills it. --}}
                <div class="absolute -left-[9999px]" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                @unless ($user)
                    <div>
                        <label for="chat-name" class="block text-sm font-medium">{{ $labels['name'] }}</label>
                        <input id="chat-name" name="name" type="text" required maxlength="120" autocomplete="name" class="mt-1 w-full rounded-[var(--radius-md)] border border-[var(--border-interactive)] bg-[var(--surface)] px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="chat-email" class="block text-sm font-medium">{{ $labels['email'] }}@if (! (bool) setting('chat.require_email', true)) <span class="font-normal text-[var(--text-muted)]">({{ __('optional') }})</span>@endif</label>
                        <input id="chat-email" name="email" type="email" maxlength="191" autocomplete="email" @if ((bool) setting('chat.require_email', true)) required @endif class="mt-1 w-full rounded-[var(--radius-md)] border border-[var(--border-interactive)] bg-[var(--surface)] px-3 py-2 text-sm">
                    </div>
                @endunless

                <div class="flex-1">
                    <label for="chat-first" class="sr-only">{{ $labels['message'] }}</label>
                    <textarea id="chat-first" name="message" required rows="3" maxlength="2000" placeholder="{{ $labels['message'] }}" class="mt-1 w-full rounded-[var(--radius-md)] border border-[var(--border-interactive)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                </div>

                <p data-chat-error role="alert" hidden class="text-sm text-[var(--danger)]"></p>
                <button type="submit" class="btn btn-brand btn-sm">{{ $labels['start'] }}</button>
            </form>

            {{-- The conversation. --}}
            <div data-chat-thread hidden class="flex flex-1 flex-col overflow-hidden">
                <ol data-chat-messages aria-live="polite" aria-relevant="additions" class="flex flex-1 flex-col gap-2 overflow-y-auto p-4 text-sm"></ol>
                {{-- While the assistant is answering. Not a link in a menu and
                     not a phrase the visitor has to guess: a button, in the
                     conversation, the whole time. --}}
                <div data-chat-agent-bar hidden class="flex items-center justify-between gap-2 border-t border-[var(--border)] bg-[var(--surface-sunken)] px-3 py-2">
                    <span class="inline-flex items-center gap-1.5 text-xs text-[var(--text-muted)]">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2m0 14v2m9-9h-2M5 12H3m3.5-6.5 1.5 1.5m8 8 1.5 1.5m0-11-1.5 1.5m-8 8-1.5 1.5" /><circle cx="12" cy="12" r="3.5" /></svg>
                        <span data-chat-agent-name>{{ __(':name is answering', ['name' => $labels['agent']]) }}</span>
                    </span>
                    <button type="button" data-chat-human class="shrink-0 rounded-full border border-[var(--border-interactive)] px-2.5 py-1 text-xs font-semibold text-[var(--brand-primary)] hover:bg-[var(--surface)]">{{ $labels['human'] }}</button>
                </div>

                <p data-chat-typing hidden class="px-4 pb-2 text-xs text-[var(--text-muted)]">{{ $labels['thinking'] }}</p>

                <p data-chat-closed hidden class="border-t border-[var(--border)] px-4 py-3 text-sm text-[var(--text-muted)]">
                    {{ $labels['closed'] }}
                    <button type="button" data-chat-new class="ml-1 font-semibold text-[var(--brand-primary)] hover:underline">{{ $labels['newChat'] }}</button>
                </p>
                <form data-chat-reply class="flex items-end gap-2 border-t border-[var(--border)] p-3">
                    <label for="chat-reply" class="sr-only">{{ $labels['message'] }}</label>
                    <textarea id="chat-reply" name="body" rows="1" required maxlength="2000" placeholder="{{ $labels['message'] }}" class="max-h-32 min-h-10 flex-1 resize-none rounded-[var(--radius-md)] border border-[var(--border-interactive)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                    <button type="submit" class="btn btn-brand btn-sm shrink-0" aria-label="{{ $labels['send'] }}">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.27 3.13a.5.5 0 0 1 .66-.65L21 12 3.93 21.52a.5.5 0 0 1-.66-.65L6 12Zm0 0h6" /></svg>
                    </button>
                </form>
            </div>
        </section>

        {{-- The launcher. --}}
        <button
            type="button"
            data-chat-launcher
            aria-expanded="false"
            class="btn btn-brand relative shadow-[var(--shadow-lg)]"
        >
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 0 1 1.037-.443 48.282 48.282 0 0 0 5.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" /></svg>
            <span>{{ $labels['button'] }}</span>
            <span data-chat-unread hidden class="absolute -right-1 -top-1 min-w-5 rounded-full bg-[var(--brand-secondary)] px-1.5 text-center text-xs font-bold text-[var(--text-on-secondary)]"></span>
        </button>

        {{-- The words chat.js needs that are not already on the page. --}}
        <script type="application/json" data-chat-labels>{!! $labelsJson !!}</script>
    </div>
@endif
