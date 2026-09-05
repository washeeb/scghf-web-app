{{--
    The announcement bar.

    Drawn above the header when there is a live notice for this page.

    ── One mechanism, not two ──────────────────────────────────────────────────

    This used to read an `announcement.*` settings group, added in the same
    module that built the settings screen. The `announcements` table had been
    there since Phase 3 — with dismissal, path targeting, three placements,
    scheduling and impression counting — and nothing rendered it, so the
    settings version looked like the only one there was.

    Two mechanisms for one bar is worse than either alone: the foundation would
    have edited one and wondered why the site showed the other. The table wins,
    because it does everything the settings did and five things they could not,
    and the settings group has been removed.

    ── The tone is a border, not a background ──────────────────────────────────

    `success`, `warning`, `danger` and `info` are declared in the palette as
    TEXT colours, each checked for 4.5:1 against `bg`. Using one as a fill and
    putting white on it would be inventing a colour pair nothing has checked —
    and amber in particular fails badly. So the bar keeps a checked background
    and expresses its tone through a border and the link colour, both of which
    are legible by construction.

    ── It is a region, not an alert ────────────────────────────────────────────

    `role="region"` with a label, so a screen-reader user can skip it or return
    to it by name. Not `role="alert"`: an alert interrupts whatever is being
    read out, and a fundraising notice is not an emergency. Not `aria-live`
    either — the bar is present at page load, so there is no change to announce.

    ── Dismissal degrades to "it stays" ────────────────────────────────────────

    Closing it writes a cookie for `dismiss_days`. The close button is rendered
    by the script rather than by Blade, so it cannot exist without the code that
    makes it work — a control that does nothing when pressed is worse than one
    that was never offered.
--}}
@php
    $announcement = App\Models\Announcement::forRequest('announcement_bar', request());
@endphp

@if ($announcement !== null)
    @php
        $announcement->recordImpression();

        /*
         * A closed vocabulary resolving to a token, the same rule the page
         * builder follows: the stored value is LOOKED UP here rather than
         * interpolated, so a `style` column edited by hand can only ever
         * produce one of these four.
         */
        $tone = match ($announcement->style) {
            'urgent' => 'var(--danger)',
            'warning' => 'var(--warning)',
            'success' => 'var(--success)',
            default => 'var(--info)',
        };
    @endphp

    <div
        role="region"
        aria-label="{{ __('Announcement') }}"
        class="border-b-2 bg-[var(--surface)] text-[var(--text-primary)]"
        style="border-bottom-color: {{ $tone }}"
        data-announcement="{{ $announcement->ulid }}"
        data-announcement-close-label="{{ __('Close this announcement') }}"
        @if ($announcement->is_dismissible) data-announcement-dismissible @endif
    >
        <div
            data-announcement-actions
            class="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4 py-2 text-center text-sm"
        >
            <p>{{ $announcement->title }}</p>

            @if ($announcement->cta_url && $announcement->cta_label)
                <a
                    href="{{ route('announcements.click', $announcement) }}"
                    class="font-semibold underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                    style="color: {{ $tone }}"
                >{{ $announcement->cta_label }}</a>
            @endif
        </div>
    </div>
@endif
