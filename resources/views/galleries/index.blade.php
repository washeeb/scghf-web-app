{{--
    The albums.

    Each card is one link wrapping the cover and the title, so the tap target is
    the card rather than four words of heading — the difference between a
    listing that works on a phone and one that does not.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Gallery')"
    :lead="__('Photographs from our work, published with the consent of the people in them.')"
>
    @forelse ($galleries as $gallery)
        @if ($loop->first)
            <ul role="list" class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        @endif

        <li>
            <a
                href="{{ route('galleries.show', $gallery) }}"
                class="group block rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >
                @if ($gallery->cover)
                    <x-media.image
                        :media="$gallery->cover"
                        size="card"
                        :eager="$loop->index < 3"
                        class="mb-3 aspect-[3/2] w-full rounded-lg object-cover"
                    />
                @endif

                <h2 class="font-semibold text-[var(--text-primary)] group-hover:text-[var(--brand-primary)]">
                    {{ $gallery->title }}
                </h2>

                <p class="mt-1 text-sm text-[var(--text-muted)]">
                    {{ collect([
                        $gallery->location,
                        $gallery->taken_on?->toFormattedDateString(),
                        trans_choice('{0}No photographs yet|{1}1 photograph|[2,*]:count photographs', $gallery->items_count, ['count' => $gallery->items_count]),
                    ])->filter()->implode(' · ') }}
                </p>
            </a>
        </li>

        @if ($loop->last)
            </ul>
        @endif
    @empty
        <p class="text-[var(--text-secondary)]">{{ __('There are no albums here yet.') }}</p>
    @endforelse

    @if ($galleries->hasPages())
        <div class="mt-10">{{ $galleries->onEachSide(1)->links() }}</div>
    @endif
</x-site.page-shell>
