{{--
    The door. A code box, what it found, one button (in the header).

    The result is big on purpose: read across a table at arm's length, on a
    phone, in whatever light the venue has.
--}}
<x-filament-panels::page>
    <form wire:submit="lookUp" class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="door-code" class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('Ticket code or registration reference') }}</label>
            <input
                id="door-code"
                type="text"
                wire:model="code"
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                autofocus
                class="fi-input mt-1 block w-full rounded-lg border-gray-300 font-mono text-2xl uppercase tracking-widest shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
            >
        </div>
        <x-filament::button type="submit" size="lg">{{ __('Look up') }}</x-filament::button>
    </form>

    @if ($problem)
        <div role="alert" class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-danger-800 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-300">{{ $problem }}</div>
    @endif

    @if ($ticket)
        @php($event = $ticket->event)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $event?->title }} · {{ $event?->starts_at?->format('D j M, g:i a') }}</p>
            <p class="mt-2 font-mono text-4xl font-bold tracking-widest text-gray-950 dark:text-white">{{ $ticket->code }}</p>
            <p class="mt-2 text-2xl text-gray-950 dark:text-white">{{ $ticket->holder_name }}</p>
            @if ($ticket->ticketType)
                <p class="text-gray-500 dark:text-gray-400">{{ $ticket->ticketType->name }}</p>
            @endif

            <p class="mt-4 text-xl font-semibold">
                @if ($ticket->cancelled_at)
                    <span class="text-danger-600 dark:text-danger-400">{{ __('CANCELLED — do not admit.') }}</span>
                @elseif ($ticket->checked_in_at)
                    <span class="text-warning-600 dark:text-warning-400">{{ __('ALREADY USED at :time.', ['time' => $ticket->checked_in_at->format('H:i')]) }}</span>
                @else
                    <span class="text-success-600 dark:text-success-400">{{ __('Valid — admit.') }}</span>
                @endif
            </p>
        </div>
    @elseif ($registration)
        @php($event = $registration->event)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $event?->title }} · {{ $event?->starts_at?->format('D j M, g:i a') }}</p>
            <p class="mt-2 font-mono text-3xl font-bold tracking-widest text-gray-950 dark:text-white">{{ $registration->reference }}</p>
            <p class="mt-2 text-2xl text-gray-950 dark:text-white">{{ $registration->name }}</p>
            <p class="text-gray-500 dark:text-gray-400">{{ trans_choice('{1}:count person|[2,*]:count people', $registration->headcount(), ['count' => $registration->headcount()]) }}</p>

            <p class="mt-4 text-xl font-semibold">
                @if ($registration->status === \App\Models\EventRegistration::STATUS_ATTENDED)
                    <span class="text-warning-600 dark:text-warning-400">{{ __('ALREADY CHECKED IN.') }}</span>
                @elseif ($registration->status === \App\Models\EventRegistration::STATUS_REGISTERED)
                    <span class="text-success-600 dark:text-success-400">{{ __('Registered — admit.') }}</span>
                @else
                    <span class="text-danger-600 dark:text-danger-400">{{ __('Not on the list (:status).', ['status' => $registration->status]) }}</span>
                @endif
            </p>
        </div>
    @endif
</x-filament-panels::page>
