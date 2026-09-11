@props(['items'])

{{--
    The navigation on a phone.

    ── An expanding panel, not a full-screen overlay ───────────────────────────

    A slide-over covering the page is a modal, and a modal owes the visitor a
    focus trap, `inert` on everything behind it, a scroll lock, and a way out
    that is not the back button. Every one of those is a thing to get wrong, and
    getting one wrong strands a keyboard or screen-reader user inside a menu.

    An in-flow panel below the header owes none of them. It is a disclosure:
    focus moves into it by tabbing and out of it by tabbing, the page behind it
    is still the page, and nothing is trapped. With seven top-level items that
    is not a compromise — it is the simpler thing that is also the more correct
    one.

    ── `<details>`, so it works with no JavaScript at all ──────────────────────

    The browser already implements disclosure: click and Enter open it, the
    expanded state is announced without any ARIA from us, and it needs no script
    to function. `resources/js/navigation.js` adds Escape, click-away and
    closing on resize — conveniences, every one of which is absent rather than
    broken if the script never arrives.

    That matters here specifically. This site's visitors are on low-end Android
    phones, sometimes behind data-saver proxies that rewrite or drop scripts,
    and a navigation that depends on JavaScript is a site those visitors cannot
    move around.
--}}

<details
    data-mobile-nav
    class="group/panel lg:hidden"
>
    <summary
        class="flex cursor-pointer list-none items-center rounded-md p-2 text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        aria-label="{{ __('Menu') }}"
    >
        {{-- Two icons, one shown at a time, so the control says what it will do
             next rather than what state it is in. --}}
        <svg class="size-6 group-open/panel:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" />
        </svg>

        <svg class="hidden size-6 group-open/panel:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" d="M6 6l12 12M18 6L6 18" />
        </svg>
    </summary>

    {{--
        Positioned against the header rather than inside the flex row, so the
        panel spans the full width of the screen instead of the width of the
        button it hangs off.
    --}}
    <div
        class="absolute inset-x-0 top-full z-40 max-h-[calc(100vh-4rem)] overflow-y-auto border-b border-[var(--border)] bg-[var(--bg)] px-4 py-3 shadow-lg"
    >
        <nav aria-label="{{ __('Primary, mobile') }}">
            <ul class="divide-y divide-[var(--border)]">
                @foreach ($items as $item)
                    @php $children = $item->children->filter(fn ($child) => $child->isRenderable()); @endphp

                    <li class="py-1">
                        @if ($children->isEmpty())
                            <x-site.menu-link :item="$item" class="block py-2 text-base" />
                        @else
                            {{-- A nested disclosure. The parent's own page is
                                 the first entry inside, because a parent that
                                 became a pure toggle would make its page
                                 unreachable — /about exists and is published. --}}
                            <details class="group/section">
                                <summary
                                    class="flex cursor-pointer list-none items-center justify-between py-2 text-base font-medium text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                                >
                                    {{ $item->label }}

                                    <svg class="size-5 transition-transform group-open/section:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </summary>

                                <ul class="mb-2 ml-3 border-l border-[var(--border)] pl-3">
                                    @if ($url = $item->resolveUrl())
                                        <li>
                                            <a
                                                href="{{ $url }}"
                                                class="block py-2 text-sm text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                                            >{{ $item->label }}</a>
                                        </li>
                                    @endif

                                    @foreach ($children as $child)
                                        <li>
                                            <x-site.menu-link :item="$child" class="block py-2 text-sm" />
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>

        {{-- Signing in lives here below `md`, so the header's right-hand cluster
             keeps the donate button in the corner a thumb reaches first. --}}
        <div class="mt-3 border-t border-[var(--border)] pt-3">
            <x-site.account-nav />
        </div>
    </div>
</details>
