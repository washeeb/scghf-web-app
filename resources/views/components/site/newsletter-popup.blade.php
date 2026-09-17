{{--
    The newsletter popup.

    ── A <dialog>, so the browser does the hard parts ──────────────────────────

    `showModal()` traps focus, makes everything behind inert, closes on
    Escape and restores focus to where it was. Hand-rolling any of that is
    how a popup becomes the least accessible thing on a site.

    ── Rendered only when it could show ────────────────────────────────────────

    Not on the donation, basket, checkout, account or sign-in pages — nobody
    entering a card number or a password should have anything land on top
    of it — and not at all when the setting is off or the visitor has
    already subscribed (a cookie the subscribe handler sets). Frequency is
    the JavaScript's job, in localStorage: this page cannot know when the
    last one was closed.

    ── Same form as the footer ─────────────────────────────────────────────────

    Same route, same honeypot, same consent sentence from the settings
    layer. `source=popup` is the only difference, so the foundation can see
    whether the thing earns its keep.
--}}
@php
    $enabled = (bool) setting('site.newsletter_popup_enabled', false)
        && Route::has('newsletter.subscribe')
        && ! request()->cookie('scghf_subscribed')
        && ! request()->routeIs('donate*', 'give*', 'pay*', 'shop.cart*', 'shop.checkout*', 'shop.track*', 'account.*', 'login', 'register', 'password.*', 'two-factor.*', 'newsletter.*', 'tickets.*', 'events.registered');
@endphp
@if ($enabled)
    <dialog
        id="newsletter-popup"
        data-newsletter-popup
        data-frequency-days="{{ (int) setting('site.newsletter_popup_frequency_days', 30) }}"
        data-delay-seconds="{{ (int) setting('site.newsletter_popup_delay_seconds', 25) }}"
        aria-labelledby="newsletter-popup-heading"
        class="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-0 text-[var(--text-primary)] shadow-xl backdrop:bg-black/50"
    >
        <form method="POST" action="{{ route('newsletter.subscribe') }}" class="space-y-4 p-6">
            @csrf
            <x-honeypot />
            <input type="hidden" name="source" value="popup">

            <div class="flex items-start justify-between gap-4">
                <h2 id="newsletter-popup-heading" class="text-xl font-bold">{{ setting('site.newsletter_popup_heading', __('Before you go')) }}</h2>
                <button type="button" data-newsletter-popup-close class="-mr-2 -mt-2 rounded p-2 text-2xl leading-none text-[var(--text-muted)] hover:text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]" aria-label="{{ __('Close') }}">&times;</button>
            </div>

            <p class="text-[var(--text-secondary)]">{{ setting('site.newsletter_popup_body') }}</p>

            <div>
                <label for="newsletter-popup-email" class="mb-1 block text-sm font-medium">{{ __('Email address') }}</label>
                <input id="newsletter-popup-email" type="email" name="email" required autocomplete="email" class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2">
            </div>

            <p class="text-xs text-[var(--text-muted)]">{{ setting('compliance.newsletter_consent_text') }}</p>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">{{ __('Keep me posted') }}</button>
                <button type="button" data-newsletter-popup-close class="text-sm text-[var(--text-muted)] underline-offset-2 hover:underline">{{ __('No thanks') }}</button>
            </div>
        </form>
    </dialog>
@endif
