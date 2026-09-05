{{--
    503. Branded, and inside the site's own layout.

    An unstyled framework error page on a donation site reads as "this is
    broken" or, worse, as a different site entirely — which is exactly the
    moment a donor abandons a payment. Every one of these keeps the header, the
    footer and the theme, so the visitor can see where they are and get back.
--}}
<x-layouts.app title="Back shortly">
    <div class="mx-auto max-w-2xl px-4 py-24 text-center">
        <p class="text-sm font-semibold tracking-widest text-[var(--text-muted)]">503</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-[var(--text-primary)]">{{ __('Back shortly') }}</h1>
        <p class="mt-4 text-[var(--text-muted)]">{{ __('The site is briefly unavailable while we make a change. Please try again in a few minutes.') }}</p>

        <a
            href="{{ url('/') }}"
            class="mt-8 inline-block rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >{{ __('Back to the home page') }}</a>
    </div>
</x-layouts.app>
