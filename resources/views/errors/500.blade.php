{{--
    500. Branded, and inside the site's own layout.

    An unstyled framework error page on a donation site reads as "this is
    broken" or, worse, as a different site entirely — which is exactly the
    moment a donor abandons a payment. Every one of these keeps the header, the
    footer and the theme, so the visitor can see where they are and get back.
--}}
<x-layouts.app title="Something went wrong">
    <div class="mx-auto max-w-2xl px-4 py-24 text-center">
        <p class="text-sm font-semibold tracking-widest text-[var(--text-muted)]">500</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-[var(--text)]">{{ __('Something went wrong') }}</h1>
        <p class="mt-4 text-[var(--text-muted)]">{{ __('The problem has been recorded and somebody will look at it. Nothing you were doing was saved.') }}</p>

        <a
            href="{{ url('/') }}"
            class="mt-8 inline-block rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
        >{{ __('Back to the home page') }}</a>
    </div>
</x-layouts.app>
