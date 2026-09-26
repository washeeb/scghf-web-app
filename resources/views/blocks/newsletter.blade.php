{{--
    Email capture.

    The form is rendered only when a route exists to receive it — a form posting
    to a 404 is worse than no form, because somebody types their address into it
    and believes they have subscribed.
--}}
<x-blocks.section :section="$section" :eyebrow="$section->field('eyebrow')" :heading="$section->field('heading')" :intro="$section->field('intro')">
    @if (Route::has('newsletter.subscribe'))
        <form action="{{ route('newsletter.subscribe') }}" method="POST" class="flex max-w-md flex-col gap-3 sm:flex-row">
            @csrf
            <x-honeypot />
            <input type="hidden" name="source" value="block">

            <label for="newsletter-{{ $section->getKey() }}" class="sr-only">{{ __('Email address') }}</label>
            <input
                id="newsletter-{{ $section->getKey() }}"
                type="email"
                name="email"
                required
                autocomplete="email"
                placeholder="{{ __('you@example.com') }}"
                class="w-full rounded-full border border-[var(--border)] bg-[var(--bg)] px-5 py-3 text-[var(--text-primary)]"
            >

            <button
                type="submit"
                class="btn btn-brand shrink-0"
            >{{ $section->field('button_label', __('Subscribe')) }}</button>
        </form>
    @endif
</x-blocks.section>
