{{--
    One event, in a list.

    The date is the first thing on the card, as a real <time>, because the
    date is what somebody scanning a list of events is scanning for.

    @param event an App\Models\Event
    @param eager true only for the images above the fold
--}}
@props(['event', 'eager' => false])

<a
    href="{{ route('events.show', $event) }}"
    class="group block h-full rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
>
    @if ($event->featuredImage)
        <x-media.image
            :media="$event->featuredImage"
            size="card"
            :eager="$eager"
            class="mb-3 aspect-[3/2] w-full rounded-lg object-cover"
        />
    @endif

    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--brand-primary)]">
        <time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->format('D j M Y · H:i') }}</time>
    </p>

    <h3 class="mt-1 font-semibold text-[var(--text-primary)] group-hover:text-[var(--brand-primary)]">
        {{ $event->title }}
    </h3>

    <p class="mt-1 text-sm text-[var(--text-muted)]">
        {{ $event->is_online
            ? __('Online')
            : collect([$event->venue_name, $event->area, $event->region])->filter()->implode(', ') }}
    </p>

    @if ($event->status === App\Models\Event::STATUS_CANCELLED)
        <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-[var(--danger)]">{{ __('Cancelled') }}</p>
    @elseif ($event->status === App\Models\Event::STATUS_POSTPONED)
        <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-[var(--warning)]">{{ __('Postponed') }}</p>
    @elseif ($event->summary)
        <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ $event->summary }}</p>
    @endif
</a>
