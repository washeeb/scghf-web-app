{{--
    Cookie consent.

    ── Honest about what there is ─────────────────────────────────────────────

    The site sets essential cookies: the session, the CSRF token, the theme
    choice, "I closed the announcement", "I subscribed". No analytics, no
    embedded players, no third-party tags — a Phase 12 decision, and the
    reason there is nothing to block today. The banner exists so that this
    is said, so the choice is recorded with a timestamp, and so that any
    analytics or embed added later is gated before it loads rather than
    consented to after the fact.

    ── How the gate works ──────────────────────────────────────────────────────

    A future script is written as `<script type="text/plain"
    data-consent="analytics">`. Browsers do not run text/plain. When the
    visitor allows that category, cookie-consent.js swaps the type and the
    script runs; until then it is inert text. Withdrawing consent reloads
    the page, which is the only honest way to stop a script that has run.

    ── The cookie ──────────────────────────────────────────────────────────────

    `scghf_consent` = JSON {v:1, at:<unix>, analytics:bool, media:bool}, six
    months, SameSite=Lax, readable by JavaScript (so the gate can run before
    the page is interactive) and by PHP (excepted from encryption).
--}}
@if ((bool) setting('site.cookie_banner_enabled', true) && ! request()->routeIs('filament.*'))
    @php
        $policyUrl = App\Models\Page::liveUrl('cookie-policy');
    @endphp
    <div
        data-cookie-consent
        hidden
        role="region"
        aria-label="{{ __('Cookies') }}"
        class="fixed inset-x-0 bottom-0 z-40 border-t border-[var(--border)] bg-[var(--surface)] p-4 text-sm text-[var(--text-primary)] shadow-lg print:hidden"
    >
        <div class="mx-auto flex max-w-6xl flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="max-w-2xl">
                {{ setting('site.cookie_banner_text') }}
                @if ($policyUrl)
                    <a class="font-semibold text-[var(--brand-primary)] underline-offset-2 hover:underline" href="{{ $policyUrl }}">{{ __('Cookie policy') }}</a>
                @endif
            </p>
            <div class="flex flex-wrap gap-2">
                <button type="button" data-cookie-consent-open class="rounded-md border border-[var(--border)] px-4 py-2 font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">{{ __('Preferences') }}</button>
                <button type="button" data-cookie-consent-essential class="rounded-md border border-[var(--border)] px-4 py-2 font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">{{ __('Essential only') }}</button>
                <button type="button" data-cookie-consent-all class="rounded-md bg-[var(--brand-primary)] px-4 py-2 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">{{ __('Allow all') }}</button>
            </div>
        </div>
    </div>

    <dialog
        data-cookie-preferences
        aria-labelledby="cookie-prefs-heading"
        class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-0 text-[var(--text-primary)] backdrop:bg-black/50"
    >
        <form method="dialog" class="space-y-5 p-6">
            <h2 id="cookie-prefs-heading" class="text-lg font-bold">{{ __('Cookie preferences') }}</h2>

            <fieldset class="space-y-3">
                <legend class="sr-only">{{ __('Categories') }}</legend>

                <label class="flex items-start gap-3">
                    <input type="checkbox" checked disabled class="mt-1">
                    <span>
                        <span class="block font-medium">{{ __('Essential') }} <span class="font-normal text-[var(--text-muted)]">— {{ __('always on') }}</span></span>
                        <span class="block text-[var(--text-secondary)]">{{ __('Signing in, keeping your basket, the form-protection token, your light/dark choice, and remembering this choice. The site does not work without them.') }}</span>
                    </span>
                </label>

                <label class="flex items-start gap-3">
                    <input type="checkbox" name="analytics" data-cookie-category="analytics" class="mt-1">
                    <span>
                        <span class="block font-medium">{{ __('Analytics') }}</span>
                        <span class="block text-[var(--text-secondary)]">{{ __('Counting visits so we know which pages help. Not in use at the moment; if we add it, it stays off until you allow it here.') }}</span>
                    </span>
                </label>

                <label class="flex items-start gap-3">
                    <input type="checkbox" name="media" data-cookie-category="media" class="mt-1">
                    <span>
                        <span class="block font-medium">{{ __('Embedded media') }}</span>
                        <span class="block text-[var(--text-secondary)]">{{ __('Videos and maps from other companies, which set their own cookies. Today every video is a link that opens in a new tab, so nothing loads until you choose to.') }}</span>
                    </span>
                </label>
            </fieldset>

            <div class="flex flex-wrap gap-2">
                <button type="submit" value="save" data-cookie-consent-save class="rounded-md bg-[var(--brand-primary)] px-4 py-2 font-semibold text-[var(--text-on-brand)]">{{ __('Save') }}</button>
                <button type="submit" value="cancel" class="rounded-md border border-[var(--border)] px-4 py-2 font-medium">{{ __('Cancel') }}</button>
            </div>
        </form>
    </dialog>
@endif
