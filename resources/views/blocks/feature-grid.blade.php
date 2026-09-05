{{-- Icon cards in a row. --}}
@php
    $items = collect($section->field('items', []))->filter(fn ($item) => filled($item['title'] ?? null));
    $columns = max(1, min(4, (int) $section->field('columns', 4)));
@endphp

@if ($items->isNotEmpty())
    <x-blocks.section :section="$section" :heading="$section->field('heading')" :intro="$section->field('intro')">
        <ul @class([
            'grid gap-6',
            'sm:grid-cols-2' => $columns >= 2,
            'lg:grid-cols-3' => $columns === 3,
            'lg:grid-cols-4' => $columns >= 4,
        ])>
            @foreach ($items as $item)
                <li class="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-6">
                    <h3 class="font-semibold text-[var(--text)]">{{ $item['title'] }}</h3>

                    @if (filled($item['body'] ?? null))
                        <p class="mt-2 text-sm text-[var(--text-muted)]">{{ $item['body'] }}</p>
                    @endif

                    @if (filled($item['url'] ?? null))
                        <a href="{{ $item['url'] }}" class="mt-3 inline-block text-sm font-semibold text-[var(--brand-primary)] hover:underline">
                            {{ __('Read more') }}
                            <span class="sr-only">{{ __('about') }} {{ $item['title'] }}</span>
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-blocks.section>
@endif
