{{--
    The site header, rendered from the `header` menu and the settings layer.

    Nothing here is typed content. The wordmark, the nav, the donate label and
    the destination all come from the database, so the foundation can change any
    of them without a deploy.
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

<header class="border-b border-[var(--border)] bg-[var(--bg)]">
    <nav
        class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3"
        aria-label="{{ __('Primary') }}"
    >
        <a href="{{ url('/') }}" class="font-semibold tracking-tight text-[var(--text)]">
            {{ $wordmark }}
        </a>

        {{--
            The nav itself.

            Hidden below `md` rather than collapsed into a JavaScript menu at
            this stage: a disclosure widget that half-works is worse than none,
            and the mobile menu belongs with the component work in Phase 5. The
            links remain reachable from the footer, which renders the same
            menus — so nothing is unreachable on a phone in the meantime.
        --}}
        <ul class="ml-auto hidden items-center gap-1 md:flex">
            @foreach ($nav as $item)
                <li>
                    <x-site.menu-link :item="$item" />
                </li>
            @endforeach
        </ul>

        <div class="ml-auto flex items-center gap-2 md:ml-0">
            <x-site.theme-toggle />

            {{-- Sign in, or the account. Session state rather than content, so
                 it is a component rather than a menu item — see the component
                 for why. Hidden below `sm` so the donate button keeps the
                 thumb-reachable corner of a phone to itself. --}}
            <div class="hidden sm:block">
                <x-site.account-nav />
            </div>

            {{--
                The donate call to action.

                Always present and always last, so it is the final thing in the
                tab order of the header and the first thing a thumb reaches on a
                phone. Its destination comes from the menu if one is marked as
                the highlighted item, so the foundation can point it at a
                specific appeal during a campaign.
            --}}
            <a
                href="{{ $donateUrl }}"
                class="rounded-md bg-[var(--brand-secondary)] px-4 py-2 text-sm font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
            >
                {{ $donateLabel }}
            </a>
        </div>
    </nav>
</header>
