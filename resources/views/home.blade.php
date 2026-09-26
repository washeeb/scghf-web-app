{{--
    A holding home page.

    Deliberately minimal: it exists so the layout shell is reachable and
    testable, and it is replaced by CMS page rendering in Phase 5 rather than
    grown into it. Everything visible still comes from the settings layer, so it
    does not become the first place real content gets hardcoded.
--}}
<x-layouts.app>
    <div class="mx-auto max-w-6xl px-4 py-16">
        <h1 class="text-3xl font-semibold tracking-tight text-[var(--text-primary)] sm:text-4xl">
            {{ setting('general.legal_name', setting('general.short_name', config('app.name'))) }}
        </h1>

        @if ($motto = setting('general.motto'))
            <p class="mt-3 text-lg text-[var(--text-muted)]">{{ $motto }}</p>
        @endif

        <p class="mt-8 max-w-prose text-[var(--text-muted)]">
            {{ setting('seo.default_description', '') }}
        </p>
    </div>
</x-layouts.app>
