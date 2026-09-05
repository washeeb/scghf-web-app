{{--
    Quotes.

    ── A list, not a carousel ──────────────────────────────────────────────────

    The block is described as a carousel and renders as a list on purpose. A
    carousel needs JavaScript to show anything past the first slide, hides most
    of its content from search engines, auto-advances past people who read
    slowly, and is measurably ignored by visitors. Three quotes side by side
    say the same thing, work with no script, and are readable.

    Every testimonial here has recorded consent — `Testimonial` refuses to be
    published without it.
--}}
@if ($testimonials->isNotEmpty())
    <x-blocks.section :section="$section" :heading="$section->field('heading')">
        <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($testimonials as $testimonial)
                <li>
                    <figure class="flex h-full flex-col rounded-lg border border-[var(--border)] bg-[var(--surface)] p-6">
                        <blockquote class="flex-1 text-[var(--text)]">
                            <p>{{ $testimonial->quote }}</p>
                        </blockquote>

                        <figcaption class="mt-4 flex items-center gap-3">
                            @if ($testimonial->photo?->isPublishable())
                                <x-media.image :media="$testimonial->photo" size="thumb" class="size-10 rounded-full object-cover" />
                            @endif

                            <span>
                                <span class="block font-semibold text-[var(--text)]">{{ $testimonial->author_name }}</span>

                                @if ($testimonial->author_role)
                                    <span class="block text-sm text-[var(--text-muted)]">{{ $testimonial->author_role }}</span>
                                @endif
                            </span>
                        </figcaption>
                    </figure>
                </li>
            @endforeach
        </ul>
    </x-blocks.section>
@endif
