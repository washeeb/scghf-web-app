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
    <x-blocks.section :section="$section" :eyebrow="$section->field('eyebrow')" :heading="$section->field('heading')">
        <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($testimonials as $testimonial)
                <li>
                    <figure class="flex h-full flex-col rounded-[var(--radius-xl)] border border-[var(--border)] bg-[var(--surface)] p-6 shadow-[var(--shadow-sm)]">
                        <blockquote class="flex-1 text-[var(--text-primary)]">
                            <svg class="mb-3 size-7 text-[var(--brand-secondary)]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7.17 6A5.17 5.17 0 0 0 2 11.17V18h6.5v-6.5H5.25a1.92 1.92 0 0 1 1.92-1.92V6Zm9.58 0a5.17 5.17 0 0 0-5.17 5.17V18h6.5v-6.5h-3.25a1.92 1.92 0 0 1 1.92-1.92V6Z" /></svg>
                            <p>{{ $testimonial->quote }}</p>
                        </blockquote>

                        <figcaption class="mt-4 flex items-center gap-3">
                            @if ($testimonial->photo?->isPublishable())
                                <x-media.image :media="$testimonial->photo" size="thumb" class="size-10 rounded-full object-cover" />
                            @endif

                            <span>
                                <span class="block font-semibold text-[var(--text-primary)]">{{ $testimonial->author_name }}</span>

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
