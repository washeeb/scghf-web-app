{{--
    The site header, rendered from the `header` menu and the settings layer.

    Nothing here is typed content. The wordmark, the logo, the nav, the donate
    label and the destination all come from the database, so the foundation can
    change any of them without a deploy.

    ── One menu, two renderings ────────────────────────────────────────────────

    The same items are rendered twice: as a row of dropdowns at `lg` and above,
    and as an expanding panel below it — `lg`, not `md`, because at 768px eight
    items, a theme control, a basket and a donate pill wrap onto two lines.
    Only ever one of the two is displayed,
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
        // The first line of the street address, with the city — enough to
        // place the office, short enough for a strip.
        $topBarPlace = collect([
            \Illuminate\Support\Str::before((string) setting('contact.address', ''), "\n"),
            setting('contact.city'),
        ])->filter()->implode(', ');
    @endphp

    @if ($topBarPhone || $topBarEmail || $topBarHours || $topBarPlace)
        {{-- The template's strip: on the accent colour, contact on the left,
             email and hours on the right. The ink is the token that passes AA
             on the orange; white on it does not. --}}
        <div class="hidden bg-[var(--brand-secondary)] text-xs font-medium text-[var(--text-on-secondary)] sm:block">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-1 px-4 py-1.5">
                @if ($topBarPlace)
                    <span class="inline-flex items-center gap-1.5">
                        <x-ui.icon name="home" class="size-3.5" />
                        {{ $topBarPlace }}
                    </span>
                @endif

                @if ($topBarPhone)
                    <a class="inline-flex items-center gap-1.5 hover:underline" href="tel:{{ preg_replace('/\s+/', '', $topBarPhone) }}">
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                        {{ $topBarPhone }}
                    </a>
                @endif

                <span class="ml-auto flex flex-wrap items-center gap-x-6 gap-y-1">
                    @if ($topBarEmail)
                        <a class="inline-flex items-center gap-1.5 hover:underline" href="mailto:{{ $topBarEmail }}">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                            {{ $topBarEmail }}
                        </a>
                    @endif

                    @if ($topBarHours)
                        <span>{{ $topBarHours }}</span>
                    @endif
                </span>
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
        {{-- Settings → Site → "Open menus on hover". Read by navigation.js;
             it only ever applies to a pointer that can hover (a mouse), so
             touch and keyboard keep the click-to-open disclosure. --}}
        data-nav-hover="{{ (bool) setting('site.nav_open_on_hover', true) ? '1' : '0' }}"
    >
        <a href="{{ url('/') }}" class="mr-auto flex items-center gap-2 font-semibold tracking-tight text-[var(--text-primary)]">
            @if ($logoLight || $logoDark)
                {{-- When only one file is uploaded it serves both themes. The
                     alternative — showing nothing in dark until somebody
                     uploads a second file — is a site with no logo. --}}
                {{-- `sizes` says how wide the logo is drawn; without it the
                     browser assumes full width and fetches the 1600px file
                     for a 200px logo. --}}
                <x-media.image
                    :media="$logoLight ?? $logoDark"
                    size="thumb"
                    :eager="true"
                    sizes="220px"
                    class="h-10 w-auto sm:h-12 {{ $logoDark && $logoLight ? 'dark:hidden' : '' }}"
                />

                @if ($logoDark && $logoLight)
                    <x-media.image
                        :media="$logoDark"
                        size="thumb"
                        :eager="true"
                        sizes="220px"
                        class="hidden h-10 w-auto sm:h-12 dark:block"
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
        <ul class="hidden items-center gap-0.5 lg:flex">
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
            @if (Route::has('search') && app(App\Support\Features::class)->enabled('site_search'))
                <a
                    href="{{ route('search') }}"
                    class="hidden rounded-full p-2 text-[var(--text-primary)] hover:bg-[var(--surface-sunken)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)] md:inline-flex"
                    aria-label="{{ __('Search') }}"
                >
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                </a>
            @endif

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
            <div class="hidden lg:block">
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
                class="btn btn-sm btn-brand uppercase tracking-wide"
            >
                {{ $donateLabel }}
            </a>

            {{-- The theme, as an icon that opens a menu — after the donate
                 button, so the row ends with the one control that is the same
                 on every page and the button that matters stays where a thumb
                 lands. --}}
            <x-site.theme-toggle />

            {{-- Last in the DOM, so the tab order reaches the donate button
                 before the menu toggle — and drawn last, so on a phone it sits
                 in the corner. --}}
            <x-site.mobile-nav :items="$nav" />
        </div>
    </nav>
</header>
