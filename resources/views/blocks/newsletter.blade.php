{{--
    Email capture.

    The form is rendered only when a route exists to receive it — a form posting
    to a 404 is worse than no form, because somebody types their address into it
    and believes they have subscribed.
--}}
<x-blocks.section :section="$section" :heading="$section->field('heading')" :intro="$section->field('intro')">
    @if (Route::has('newsletter.subscribe'))
        <form action="{{ route('newsletter.subscribe') }}" method="POST" class="flex max-w-md flex-col gap-3 sm:flex-row">
            @csrf

            <label for="newsletter-{{ $section->getKey() }}" class="sr-only">{{ __('Email address') }}</label>
            <input
                id="newsletter-{{ $section->getKey() }}"
                type="email"
                name="email"
                required
                autocomplete="email"
                placeholder="{{ __('you@example.com') }}"
                class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-4 py-3 text-[var(--text)]"
            >

            <button
                type="submit"
                class="shrink-0 rounded-md bg-[var(--brand-primary)] px-6 py-3 font-semibold text-[var(--text-on-brand)]"
            >{{ $section->field('button_label', __('Subscribe')) }}</button>
        </form>
    @endif
</x-blocks.section>
