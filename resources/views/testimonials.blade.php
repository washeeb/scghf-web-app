{{--
    What people say.

    ── `<blockquote>` and `<cite>`, not styled divs ────────────────────────────

    The markup is what tells a screen reader that this is somebody's words and
    that is who said them. Two divs with quotation marks drawn on them read as
    one run-on paragraph.

    Every quote here has recorded consent — the model refuses to publish one
    from a beneficiary, a volunteer or a donor without it.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Testimonials')"
    :lead="__('In the words of the people we work with.')"
>
    @forelse ($testimonials as $testimonial)
        @if ($loop->first)
            <ul role="list" class="grid gap-8 sm:grid-cols-2">
        @endif

        <li class="rounded-lg border border-[var(--border)] p-6">
            <figure>
                <blockquote class="text-[var(--text-primary)]">
                    <p>{{ $testimonial->quote }}</p>
                </blockquote>

                <figcaption class="mt-4 flex items-center gap-3">
                    @if ($testimonial->photo)
                        <x-media.image
                            :media="$testimonial->photo"
                            size="thumb"
                            class="size-12 shrink-0 rounded-full object-cover"
                        />
                    @endif

                    <div class="text-sm">
                        <cite class="font-semibold not-italic text-[var(--text-primary)]">
                            {{ $testimonial->author_name }}
                        </cite>

                        <p class="text-[var(--text-muted)]">
                            {{ collect([$testimonial->author_role, $testimonial->author_location])->filter()->implode(', ') }}
                        </p>
                    </div>
                </figcaption>
            </figure>
        </li>

        @if ($loop->last)
            </ul>
        @endif
    @empty
        <p class="text-[var(--text-secondary)]">{{ __('Testimonials will appear on this page.') }}</p>
    @endforelse
</x-site.page-shell>
