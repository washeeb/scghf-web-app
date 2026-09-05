@props(['item'])

{{--
    A top-level navigation item that has children, on a wide screen.

    ── Click, not hover ────────────────────────────────────────────────────────

    A hover-opened dropdown is unusable with a finger, hostile to anybody whose
    hands are not steady, and invisible to a keyboard until it is given a pile of
    ARIA to compensate. `<details>` opens on click and on Enter, announces its own
    expanded state, and needs none of that — the browser already implements the
    disclosure pattern correctly.

    It also works with JavaScript switched off, which on a data-saver proxy that
    strips scripts is a real state rather than a hypothetical one.

    ── The parent's own page is the first item in the list ─────────────────────

    "About" is a page AND a group of pages. Turning it into a pure toggle would
    make /about unreachable from the navigation — a page that exists, is
    published, and cannot be got to. So when the parent resolves to a URL it is
    repeated as the first entry inside its own dropdown.
--}}
@php
    $parentUrl = $item->resolveUrl();
    $children = $item->children->filter(fn ($child) => $child->isRenderable());
@endphp

<details
    data-nav-dropdown
    class="group relative"
>
    <summary
        class="flex cursor-pointer list-none items-center gap-1 rounded px-3 py-2 text-sm font-medium text-[var(--text-primary)] hover:text-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
    >
        {{ $item->label }}

        <svg
            data-chevron
            class="size-4 transition-transform group-open:rotate-180"
            viewBox="0 0 20 20"
            fill="currentColor"
            aria-hidden="true"
        >
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
        </svg>
    </summary>

    <ul
        class="absolute left-0 top-full z-40 mt-1 min-w-56 rounded-md border border-[var(--border)] bg-[var(--bg)] p-1 shadow-lg"
    >
        @if ($parentUrl !== null)
            <li>
                <a
                    href="{{ $parentUrl }}"
                    class="block rounded px-3 py-2 text-sm font-medium text-[var(--text-primary)] hover:bg-[var(--surface)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                >{{ $item->label }}</a>
            </li>

            @if ($children->isNotEmpty())
                <li aria-hidden="true"><hr class="my-1 border-[var(--border)]"></li>
            @endif
        @endif

        @foreach ($children as $child)
            <li>
                <x-site.menu-link :item="$child" class="block hover:bg-[var(--surface)]" />
            </li>
        @endforeach
    </ul>
</details>
