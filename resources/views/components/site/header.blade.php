{{--
    The site header, rendered from the `header` menu and the settings layer.

    Nothing here is typed content. The wordmark, the logo, the nav, the donate
    label and the destination all come from the database, so the foundation can
    change any of them without a deploy.

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

    ── Two logo files, not one recoloured ──────────────────────────────────────

    `header.logo_light` and `header.logo_dark` are separate uploads. A logo that
    reads on white rarely reads on the dark palette, and a CSS filter that
    inverts it produces a colour the brand does not own. Either may be absent:
    one alone is used for both themes, and neither falls back to the wordmark.
--}}
@php
    $nav = App\Models\Menu::renderable('header', auth()->check());
    $wordmark = setting('general.wordmark', setting('general.short_name', config('app.name')));

    /*
     * The logos. Stored as media ids, so they are library files with the same
     * alt-text and metadata obligations as any other image — `x-media.image`
     * refuses to render one that has not been through the sanitiser.
     */
    $logoLight = ($id = setting('header.logo_light')) ? App\Models\Media::find($id) : null;
    $logoDark = ($id = setting('header.logo_dark')) ? App\Models\Media::find($id) : null;

    /*
     * Sticky by default, and settable.
     *
     * It keeps the Donate button reachable the whole way down a long page,
     * which is the entire argument for it. It also permanently spends a strip
     * of a short phone screen, which is the argument against — so it is the
     * foundation's call rather than this template's.
     */
    $sticky = setting('header.is_sticky', true);

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

{{--
    The top bar.

    Off by default. A thin strip carrying the phone number is genuinely useful
    for a foundation whose donors call as often as they click — and it is also
    the first thing to cut on a 360px screen, so it is a switch rather than a
    decision made here. Hidden below `sm` regardless: at that width it wraps
    into two lines and pushes the actual header off the first screen.
--}}
@if (setting('header.show_top_bar', false))
    @php
        $topBarPhone = setting('contact.phone_primary');
        $topBarEmail = setting('contact.email_general');
        $topBarHours = setting('contact.office_hours');
    @endphp

    @if ($topBarPhone || $topBarEmail || $topBarHours)
        <div class="hidden border-b border-[var(--border)] bg-[var(--surface)] text-xs text-[var(--text-muted)] sm:block">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-end gap-x-4 gap-y-1 px-4 py-1.5">
                @if ($topBarHours)
                    <span>{{ $topBarHours }}</span>
                @endif

                @if ($topBarPhone)
                    <a class="hover:text-[var(--brand-primary)]" href="tel:{{ preg_replace('/\s+/', '', $topBarPhone) }}">{{ $topBarPhone }}</a>
                @endif

                @if ($topBarEmail)
                    <a class="hover:text-[var(--brand-primary)]" href="mailto:{{ $topBarEmail }}">{{ $topBarEmail }}</a>
                @endif
            </div>
        </div>
    @endif
@endif

{{-- `relative`, because the mobile panel is positioned against the header
     rather than against the button it hangs off — a panel the width of a
     hamburger is not a menu. --}}
<header @class([
    'relative border-b border-[var(--border)] bg-[var(--bg)]',
    // z-30 rather than z-50: the mobile panel and the skip link both have to
    // sit above a stuck header, and a header that covers the skip link is an
    // accessibility feature cancelling itself out.
    'sticky top-0 z-30' => $sticky,
])>
    <nav
        class="mx-auto flex max-w-6xl items-center gap-2 px-4 py-3"
        aria-label="{{ __('Primary') }}"
    >
        <a href="{{ url('/') }}" class="mr-auto flex items-center gap-2 font-semibold tracking-tight text-[var(--text-primary)]">
            @if ($logoLight || $logoDark)
                {{-- When only one file is uploaded it serves both themes. The
                     alternative — showing nothing in dark until somebody
                     uploads a second file — is a site with no logo. --}}
                <x-media.image
                    :media="$logoLight ?? $logoDark"
                    size="thumb"
                    :eager="true"
                    class="h-9 w-auto {{ $logoDark && $logoLight ? 'dark:hidden' : '' }}"
                />

                @if ($logoDark && $logoLight)
                    <x-media.image
                        :media="$logoDark"
                        size="thumb"
                        :eager="true"
                        class="hidden h-9 w-auto dark:block"
                    />
                @endif

                {{-- The wordmark stays in the DOM as the accessible name of the
                     home link when the logo is decorative, and is hidden from
                     sight so the logo is not captioned. --}}
                <span class="sr-only">{{ $wordmark }}</span>
            @else
                {{ $wordmark }}
            @endif
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

            {{--
                The basket, when the shop is on.

                Only rendered with something in it — a visitor who has never
                shopped is not shown an empty basket on every page, and
                `CurrentCart::itemCount()` makes no query for them. The count is
                in the accessible name as words, so a screen reader hears
                "Basket, 3 items" rather than "Basket 3".
            --}}
            @if (Route::has('shop.cart'))
                @php $basketCount = app(App\Shop\CurrentCart::class)->itemCount(); @endphp

                @if ($basketCount > 0)
                    <a
                        href="{{ route('shop.cart') }}"
                        class="relative rounded-md p-2 text-[var(--text-primary)] hover:bg-[var(--surface)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                        aria-label="{{ trans_choice('{1}Basket, one item|[2,*]Basket, :count items', $basketCount, ['count' => $basketCount]) }}"
                    >
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                        </svg>
                        <span class="absolute -right-1 -top-1 min-w-[1.25rem] rounded-full bg-[var(--brand-primary)] px-1 text-center text-xs font-semibold text-[var(--text-on-brand)]" aria-hidden="true">{{ $basketCount }}</span>
                    </a>
                @endif
            @endif

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
                class="rounded-md bg-[var(--brand-secondary)] px-4 py-2 text-sm font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
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
