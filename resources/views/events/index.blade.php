{{--
    Events — what is coming, then what happened.

    Past events are an archive, not a deletion. "What have you actually done"
    is answered by the events that took place, and they stay reachable below
    the line.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Events')"
    :lead="setting('events.intro', __('Come and be part of the work.'))"
>
    <section aria-labelledby="upcoming-heading">
        <h2 id="upcoming-heading" class="text-xl font-bold text-[var(--text-primary)]">{{ __('Coming up') }}</h2>

        @if ($upcoming->isEmpty())
            <p class="mt-4 text-[var(--text-secondary)]">{{ __('Nothing is scheduled at the moment. Check back soon, or follow us to hear first.') }}</p>
        @else
            <ul role="list" class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($upcoming as $event)
                    <li><x-site.event-card :event="$event" :eager="$loop->index < 3" /></li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($past->isNotEmpty())
        <section class="mt-16" aria-labelledby="past-heading">
            <h2 id="past-heading" class="text-xl font-bold text-[var(--text-primary)]">{{ __('What we have done') }}</h2>

            <ul role="list" class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($past as $event)
                    <li><x-site.event-card :event="$event" /></li>
                @endforeach
            </ul>

            @if ($past->hasPages())
                <div class="mt-10">{{ $past->onEachSide(1)->links() }}</div>
            @endif
        </section>
    @endif
</x-site.page-shell>
