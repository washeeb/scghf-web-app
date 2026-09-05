{{--
    The announcement bar.

    Drawn above the header when there is something to say and today falls inside
    the window the editor set. Everything about it — the words, the link, the
    dates — is in the settings layer; see App\Support\Announcement for why the
    end date is not optional in spirit even though it is in the form.

    ── It is a region, not a banner ────────────────────────────────────────────

    `role="region"` with a label, so a screen-reader user can skip it or return
    to it by name. Not `role="alert"`: an alert interrupts whatever is being
    read out, and a fundraising notice is not an emergency. It is also not
    `aria-live` at all — the bar is present at page load rather than appearing
    later, so there is nothing to announce a change about.

    ── No dismiss button ──────────────────────────────────────────────────────

    Deliberately. Dismissal needs somewhere to remember the dismissal, which on
    this site means either a cookie the consent layer would have to cover or
    JavaScript on a page that otherwise needs none. The honest alternative is
    the end date, which the editor already has.
--}}
@php
    $announcement = app(App\Support\Announcement::class);
@endphp

@if ($announcement->isShowing())
    <div
        role="region"
        aria-label="{{ __('Announcement') }}"
        class="bg-[var(--brand-primary)] text-[var(--text-on-brand)]"
    >
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-2 text-center text-sm">
            <p>{{ $announcement->message() }}</p>

            @if ($url = $announcement->url())
                {{-- Underlined rather than coloured differently: on a coloured
                     bar there is no second colour that keeps its contrast in
                     both themes, and underline is the one link affordance that
                     does not depend on one. --}}
                <a
                    href="{{ $url }}"
                    class="font-semibold underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
                >{{ $announcement->linkLabel() }}</a>
            @endif
        </div>
    </div>
@endif
