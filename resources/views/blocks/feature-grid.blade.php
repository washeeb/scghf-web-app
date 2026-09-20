{{--
    Icon cards in a row — the template's "How we help".

    Each card: a small line icon in a tinted disc, the title, a line or two,
    and an optional link. The icon comes from the short list in
    App\Support\Icons; a card with none chosen shows its initial in the disc
    instead, so the row stays even.
--}}
@php
    $items = collect($section->field('items', []))->filter(fn ($item) => filled($item['title'] ?? null));
    $columns = max(1, min(4, (int) $section->field('columns', 4)));
@endphp

@if ($items->isNotEmpty())
    <x-blocks.section :section="$section" :eyebrow="$section->field('eyebrow')" :heading="$section->field('heading')" :intro="$section->field('intro')">
        <ul @class([
            'grid gap-5',
            'sm:grid-cols-2' => $columns >= 2,
            'lg:grid-cols-3' => $columns === 3,
            'lg:grid-cols-4' => $columns >= 4,
        ])>
            @foreach ($items as $item)
                <li class="group flex flex-col rounded-[var(--radius-xl)] border border-[var(--border)] bg-[var(--surface)] p-6 shadow-[var(--shadow-sm)] transition hover:shadow-[var(--shadow-md)]">
                    <span class="mb-5 inline-flex size-12 items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--brand-primary)_12%,transparent)] text-[var(--brand-primary)]" aria-hidden="true">
                        @if (App\Support\Icons::exists($item['icon'] ?? null))
                            <x-ui.icon :name="$item['icon']" class="size-6" />
                        @else
                            <span class="font-heading text-lg font-bold">{{ mb_substr($item['title'], 0, 1) }}</span>
                        @endif
                    </span>

                    <h3 class="text-lg font-semibold text-[var(--text-primary)]">{{ $item['title'] }}</h3>

                    @if (filled($item['body'] ?? null))
                        <p class="mt-2 text-sm leading-relaxed text-[var(--text-muted)]">{{ $item['body'] }}</p>
                    @endif

                    @if (filled($item['url'] ?? null))
                        <a href="{{ $item['url'] }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-[var(--brand-primary)] hover:underline">
                            {{ __('Read more') }}
                            <span class="sr-only">{{ __('about') }} {{ $item['title'] }}</span>
                            <svg class="size-4 transition group-hover:translate-x-0.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd" /></svg>
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-blocks.section>
@endif
