{{--
    The base layout every public page renders into.

    ── Nothing here is a hardcoded string ──────────────────────────────────────

    CLAUDE.md's CMS rule: no strings, phone numbers, emails, addresses, colours
    or images in a Blade template. Everything visible comes from `setting()`,
    the menu tables, or the theme tokens. If you find yourself typing real
    content into this file, it belongs in the CMS instead.

    ── The head order is load-bearing ──────────────────────────────────────────

    1. charset and viewport — before anything a parser could choke on
    2. the theme token block — so the first paint has the right palette
    3. the theme script — INLINE and blocking, so `.dark` is on `<html>` before
       any pixels; see App\Support\ThemePreference for why this cannot be
       deferred
    4. preconnect, then the stylesheet
    5. everything else

    Reordering 2 and 3 after 4 reintroduces the flash the whole arrangement
    exists to prevent.
--}}
@php
    $theme = app(App\Support\ThemePreference::class);
    $preference = $theme->resolve(request());

    /*
     * The head tags, as one object.
     *
     * A page that hands over a `PageMeta` gets a canonical, Open Graph and a
     * Twitter card. One that hands over nothing still gets a complete head,
     * built from the settings layer — because the alternative is a page added
     * in a year that quietly shares as a bare link, and nobody notices until
     * somebody posts it.
     */
    $meta = $meta ?? App\Support\PageMeta::site(
        $title ?? setting('seo.default_title', setting('general.short_name', config('app.name'))),
        $description ?? null,
        $noindex ?? false,
    );
@endphp
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="{{ $theme->htmlClass(request()) }}"
    data-theme="{{ $preference }}"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Read by the one script on this site that makes a request of its own:
         closing the announcement bar. Without it that POST is a 419 and the
         bar comes back on every page, which reads as a broken close button. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- The palette, from `theme_settings`. Both themes, always — a visitor
         whose system flips to dark must not need a round trip. --}}
    <style nonce="{{ $cspNonce ?? '' }}">{{ app(App\Support\ThemeTokens::class)->css() }}</style>

    {{-- Before first paint. Not deferred, not external. --}}
    <script nonce="{{ $cspNonce ?? '' }}">{!! $theme->inlineScript() !!}</script>

    {{--
        Title, description, canonical, robots, Open Graph and the Twitter card.

        All of it from `PageMeta`, which reads the record's own `HasSeo` values.
        `seoOpenGraph()` was written in Phase 3 and read by nothing, so until
        now a donation appeal shared on WhatsApp — which is how most of this
        foundation's supporters share anything — rendered as a bare blue link
        with no title, no summary and no picture.

        The indexing rule lives in `PageMeta::shouldIndex()`: two independent
        reasons not to be indexed, either sufficient. The SITE switch is off on
        staging and no page may override it; the PAGE switch is for a thank-you
        page or a receipt.
    --}}
    <x-site.meta :meta="$meta" />

    {{-- Matches the painted background, so the mobile browser chrome does not
         sit as a white bar above a dark page. --}}
    <meta name="theme-color" content="{{ $preference === 'dark' ? '#0b0b0d' : '#ffffff' }}">

    {{--
        Who this organisation is, as data.

        `NGO` structured data carrying the legal name, the registration number
        and the contact points is one of the signals that separates a real
        charity from a site impersonating one — which is the exact question a
        Ghanaian donor is asking when they reach a payment form. Every value
        comes from the settings layer, so it cannot drift from the footer, the
        receipts and the emails, all of which read the same rows.
    --}}
    <script type="application/ld+json">{!! app(App\Support\StructuredData::class)->organisation() !!}</script>
    <script type="application/ld+json">{!! app(App\Support\StructuredData::class)->website() !!}</script>

    {{-- The body typeface, preloaded: every page uses it and the CSS that
         declares it arrives later than this line does. The heading face and
         the latin-ext subsets are left to `unicode-range` — preloading a file
         a page may not need is bytes spent on a 3G connection for nothing. --}}
    <link rel="preload" href="{{ asset('fonts/Inter-latin.woff2') }}" as="font" type="font/woff2" crossorigin>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body class="min-h-screen bg-[var(--bg)] text-[var(--text-primary)] antialiased">
    {{--
        The skip link. First focusable element on the page, visually hidden
        until focused.

        Not decoration: a keyboard or screen-reader user otherwise tabs through
        every navigation item on every page before reaching the content, and
        this site's header nav is multi-level.
    --}}
    <a
        href="#main-content"
        class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:rounded focus:bg-[var(--brand-primary)] focus:px-4 focus:py-2 focus:text-[var(--text-on-brand)]"
    >
        {{ __('Skip to content') }}
    </a>

    {{-- Above the header, because the announcement is about the whole site
         rather than about the navigation, and a bar below the header reads as
         part of whatever page it happens to sit on. Renders nothing at all when
         there is no announcement or the window has passed. --}}
    <x-site.announcement />

    <x-site.header />

    <main id="main-content" tabindex="-1">
        {{ $slot }}
    </main>

    <x-site.footer />
    <x-site.newsletter-popup />
    <x-site.cookie-consent />
    <x-site.analytics />

    {{--
        The live region.

        Empty on load and written to by JavaScript when something happens that a
        sighted user would see and a screen-reader user would not — a saved
        form, a failed donation, an item added to the basket. `polite` so it
        waits for a pause rather than interrupting.
    --}}
    <div
        id="announcements"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        class="sr-only"
    ></div>

    @stack('scripts')
</body>
</html>
