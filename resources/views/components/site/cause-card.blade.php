{{--
    One appeal, in a list, with its progress.

    The progress bar is inside the card on purpose: a list of appeals with no
    numbers asks somebody to click before they can tell which one needs them.

    @param cause an App\Models\Cause
    @param eager true only for the images above the fold
--}}
@props(['cause', 'eager' => false, 'level' => 'h3'])

<div class="flex h-full flex-col rounded-lg border border-[var(--border)] p-5">
    <a
        href="{{ route('causes.show', $cause) }}"
        class="group focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
    >
        @if ($cause->featuredImage)
            <x-media.image
                :media="$cause->featuredImage"
                size="card"
                :eager="$eager"
                class="mb-3 aspect-[3/2] w-full rounded-lg object-cover"
            />
        @endif

        @if ($cause->is_urgent && $cause->acceptsDonations())
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--danger)]">{{ __('Urgent') }}</p>
        @endif

        <{{ $level }} class="font-semibold text-[var(--text-primary)] group-hover:text-[var(--brand-primary)]">
            {{ $cause->title }}
        </{{ $level }}>

        @if ($cause->summary)
            <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ $cause->summary }}</p>
        @endif
    </a>

    <div class="mt-4 flex-1"></div>

    <x-site.progress :cause="$cause" />

    @if ($cause->acceptsDonations())
        <a
            href="{{ route('donate', ['cause' => $cause->slug]) }}"
            class="mt-4 rounded-md bg-[var(--brand-secondary)] px-4 py-2 text-center text-sm font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >{{ __('Support this') }}</a>
    @endif
</div>
