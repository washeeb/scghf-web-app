{{--
    The site header, rendered from the `header` menu and the settings layer.

    Nothing here is typed content. The wordmark, the nav, the donate label and
    the destination all come from the database, so the foundation can change any
    of them without a deploy.

    ── One menu, two renderings ────────────────────────────────────────────────

    The same items are rendered twice: as a row of dropdowns at `md` and above,
    and as an expanding panel below it. Only ever one of the two is displayed,
    so a screen reader is never offered the navigation twice.

    Duplicated markup rather than one tree fought into both shapes, because a
    `<details>` behaving as a disclosure on a phone and as a static row on a
    laptop means overriding the browser's own handling of `<details>` — which
    works until a browser changes how it hides the contents. Two small correct
    renderings beat one clever fragile one, and the DATA behind them is single
    sourced, which is the part that would actually rot.
--}}
@php
    $nav = App\Models\Menu::renderable('header', auth()->check());
    $wordmark = setting('general.wordmark', setting('general.short_name', config('app.name')));

    /*
     * The donate destination comes from whichever menu item is marked
     * `is_highlighted`, so the foundation can point the button at a specific
     * appeal during a campaign without a deploy. Falling back to /donate keeps
     * the button working before anybody has marked one.
     */
    $donate = $nav->first(fn ($item) => $item->is_highlighted);
    $donateUrl = $donate?->resolveUrl() ?? url('/donate');
    $donateLabel = $donate?->label ?? __('Donate');

    // The highlighted item is the button; showing it in the list as well would
    // put the same link on the page twice, one after the other.
    $nav = $nav->reject(fn ($item) => $item->is_highlighted)->values();
@endphp

{{-- `relative`, because the mobile panel is positioned against the header
     rather than against the button it hangs off — a panel the width of a
     hamburger is not a menu. --}}
<header class="relative border-b border-[var(--border)] bg-[var(--bg)]">
    <nav
        class="mx-auto flex max-w-6xl items-center gap-2 px-4 py-3"
        aria-label="{{ __('Primary') }}"
    >
        <a href="{{ url('/') }}" class="mr-auto font-semibold tracking-tight text-[var(--text)]">
            {{ $wordmark }}
        </a>

        {{-- Wide screens: a row, with a dropdown for anything that has
             children. `max_depth 1` on the seeded menu means one level, and
             this is the level. --}}
        <ul class="hidden items-center gap-1 md:flex">
            @foreach ($nav as $item)
                <li>
                    @if ($item->children->isNotEmpty())
                        <x-site.nav-dropdown :item="$item" />
                    @else
                        <x-site.menu-link :item="$item" />
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="flex items-center gap-2">
            <x-site.theme-toggle />

            {{-- Signing in. Below `md` this lives inside the mobile panel
                 instead, so the corner a thumb reaches first belongs to the
                 donate button. --}}
            <div class="hidden md:block">
                <x-site.account-nav />
            </div>

            {{--
                The donate call to action.

                Always present, at every width, and never inside the menu. It is
                the single most important control on the site, and putting it
                behind a hamburger would hide it behind a tap on exactly the
                device most of this foundation's donors use.
            --}}
            <a
                href="{{ $donateUrl }}"
                class="rounded-md bg-[var(--brand-secondary)] px-4 py-2 text-sm font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
            >
                {{ $donateLabel }}
            </a>

            {{-- Last in the DOM, so the tab order reaches the donate button
                 before the menu toggle — and drawn last, so on a phone it sits
                 in the corner. --}}
            <x-site.mobile-nav :items="$nav" />
        </div>
    </nav>
</header>
