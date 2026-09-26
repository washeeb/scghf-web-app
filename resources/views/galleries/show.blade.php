{{--
    One album.

    ── Every image goes through `x-media.image` ────────────────────────────────

    Which refuses any file whose camera metadata has not been stripped, and any
    without alt text. A gallery from a school visit is a set of photographs of
    identifiable children; the album-level consent gate is necessary and not
    sufficient, because a photograph taken outside somebody's home carries the
    coordinates of it whatever the album says.

    ── A grid of figures, not a lightbox ───────────────────────────────────────

    A lightbox is a script, a focus trap to get right, and a keyboard trap to get
    wrong. Every image is already a full-size file one tap away; the caption is
    a `<figcaption>`, which is what associates it with its picture for a screen
    reader rather than leaving a loose line of text underneath.
--}}
<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="$gallery->title">
    @if ($gallery->description || $gallery->location || $gallery->taken_on)
        <div class="mb-8 max-w-3xl space-y-2">
            @if ($gallery->description)
                <p class="text-[var(--text-secondary)]">{{ $gallery->description }}</p>
            @endif

            <p class="text-sm text-[var(--text-muted)]">
                {{ collect([$gallery->location, $gallery->taken_on?->toFormattedDateString()])->filter()->implode(' · ') }}
            </p>
        </div>
    @endif

    @forelse ($gallery->items as $item)
        @if ($loop->first)
            <ul role="list" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @endif

        <li>
            <figure>
                <x-media.image
                    :media="$item->media"
                    size="card"
                    :eager="$loop->index < 3"
                    class="w-full rounded-lg object-cover"
                />

                @if ($item->caption)
                    <figcaption class="mt-2 text-sm text-[var(--text-muted)]">{{ $item->caption }}</figcaption>
                @endif
            </figure>
        </li>

        @if ($loop->last)
            </ul>
        @endif
    @empty
        <p class="text-[var(--text-secondary)]">{{ __('This album has no photographs in it yet.') }}</p>
    @endforelse
</x-site.page-shell>
