{{--
    One ticket, for a phone screen at a door.

    Everything the steward needs is above the fold: the square, the code
    under it in a size a tired eye can read, who it is for, when. Nothing
    else on the page competes with the square — no menu of things to do, no
    donation ask. A used or cancelled ticket says so in the same place the
    square would be, so a screenshot of an old ticket does not pass as a new
    one at a glance.
--}}
<x-site.page-shell :meta="$meta">
    <div class="mx-auto max-w-md px-4 py-10">
        <article class="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-6 text-center" aria-labelledby="ticket-heading">
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">{{ __('Ticket') }}</p>
            <h1 id="ticket-heading" class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ $event?->title }}</h1>
            @if ($event)
                <p class="mt-1 text-[var(--text-secondary)]">
                    {{ $event->starts_at->format('l j F Y, g:i a') }}
                    @if ($event->venue_name) · {{ $event->venue_name }} @endif
                </p>
            @endif

            @if ($ticket->cancelled_at)
                <p role="status" class="mx-auto mt-6 rounded-lg border border-[var(--danger)] px-4 py-6 font-semibold text-[var(--text-primary)]">{{ __('This ticket was cancelled.') }}</p>
            @elseif ($ticket->checked_in_at)
                <p role="status" class="mx-auto mt-6 rounded-lg border border-[var(--border)] px-4 py-6 font-semibold text-[var(--text-secondary)]">{{ __('Used at :time.', ['time' => $ticket->checked_in_at->format('H:i, j M')]) }}</p>
            @else
                <div class="mx-auto mt-6 w-56 rounded-lg bg-white p-3" role="img" aria-label="{{ __('QR code for ticket :code', ['code' => $ticket->code]) }}">
                    {!! $qr !!}
                </div>
            @endif

            <p class="mt-4 font-mono text-3xl font-bold tracking-widest text-[var(--text-primary)]">{{ $ticket->code }}</p>
            <p class="mt-2 text-[var(--text-secondary)]">{{ $ticket->holder_name }}</p>
            @if ($ticket->ticketType)
                <p class="text-sm text-[var(--text-muted)]">{{ $ticket->ticketType->name }}</p>
            @endif

            <p class="mt-6 text-sm text-[var(--text-muted)]">{{ __('Show this screen at the door. A screenshot works; so does the code read out.') }}</p>
        </article>
    </div>
</x-site.page-shell>
