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

    {{-- The palette, from `theme_settings`. Both themes, always — a visitor
         whose system flips to dark must not need a round trip. --}}
    <style>{{ app(App\Support\ThemeTokens::class)->css() }}</style>

    {{-- Before first paint. Not deferred, not external. --}}
    <script>{!! $theme->inlineScript() !!}</script>

    <title>{{ $title ?? setting('seo.default_title', setting('general.short_name', config('app.name'))) }}</title>

    <meta name="description" content="{{ $description ?? setting('seo.default_description', '') }}">

    {{-- Staging is noindexed. `seo.allow_indexing` is seeded false and turned
         on deliberately at launch, because a staging site indexed alongside the
         real one splits its search ranking and confuses donors. --}}
    @unless (setting('seo.allow_indexing', false))
        <meta name="robots" content="noindex, nofollow">
    @endunless

    {{-- Matches the painted background, so the mobile browser chrome does not
         sit as a white bar above a dark page. --}}
    <meta name="theme-color" content="{{ $preference === 'dark' ? '#0b0b0d' : '#ffffff' }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body class="min-h-screen bg-[var(--bg)] text-[var(--text)] antialiased">
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

    <x-site.header />

    <main id="main-content" tabindex="-1">
        {{ $slot }}
    </main>

    <x-site.footer />

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
