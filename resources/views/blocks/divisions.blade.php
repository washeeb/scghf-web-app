{{--
    The four divisions, each in its own accent.

    The colour comes from the division's `colour_token`, resolved through the
    theme tokens rather than stored as a hex value — so the four accents move
    with the palette instead of drifting away from it.
--}}
@if ($divisions->isNotEmpty())
    <x-blocks.section :section="$section" :eyebrow="$section->field('eyebrow')" :heading="$section->field('heading')" :intro="$section->field('intro')">
        <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($divisions as $division)
                @php $accent = 'var(--'.(preg_replace('/[^a-z0-9-]/', '', (string) $division->colour_token) ?: 'brand-primary').')'; @endphp
                <li class="flex flex-col overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow-sm)] transition hover:shadow-[var(--shadow-md)]">
                    @if ($division->heroImage?->isPublishable())
                        <x-media.image :media="$division->heroImage" size="card" credit="title" class="aspect-[4/3] w-full object-cover" />
                    @endif

                    <div class="p-6">
                    <span
                        class="block h-1 w-12 rounded-full"
                        style="background: {{ $accent }}"
                        aria-hidden="true"
                    ></span>

                    <h3 class="mt-4 text-lg font-semibold text-[var(--text-primary)]">{{ $division->name }}</h3>

                    @if ($division->tagline)
                        <p class="mt-1 text-sm font-medium text-[var(--text-muted)]">{{ $division->tagline }}</p>
                    @endif

                    @if ($division->summary)
                        <p class="mt-2 text-sm text-[var(--text-muted)]">{{ $division->summary }}</p>
                    @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </x-blocks.section>
@endif
