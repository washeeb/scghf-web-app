@props(['item'])

{{--
    One navigation item.

    A heading with children renders as plain text rather than a dead link — the
    seeded menus use headings for grouping, and `<a href="#">` is a trap for a
    keyboard user who lands on something that looks like a link and does
    nothing.
--}}
@php
    $url = $item->resolveUrl();
    $external = $item->link_type === App\Enums\MenuItemLinkType::External;
    $current = $url !== null && request()->fullUrlIs($url . '*');
@endphp

@if ($url === null)
    <span class="px-3 py-2 text-sm font-medium text-[var(--text-muted)]">{{ $item->label }}</span>
@else
    <a
        href="{{ $url }}"
        @if ($item->opens_in_new_tab || $external)
            target="_blank"
            {{-- noopener is not optional on a target=_blank link: without it the
                 opened page can reach back through window.opener. --}}
            rel="noopener noreferrer"
        @endif
        @if ($current) aria-current="page" @endif
        class="rounded px-3 py-2 text-sm font-medium text-[var(--text)] hover:text-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)] aria-[current=page]:text-[var(--brand-primary)]"
    >
        {{ $item->label }}
        @if ($external)
            {{-- Announced, not just drawn. A sighted user infers "new tab" from
                 an icon; a screen-reader user gets nothing unless it is said. --}}
            <span class="sr-only">{{ __('(opens in a new tab)') }}</span>
        @endif
    </a>
@endif
