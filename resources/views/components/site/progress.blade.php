{{--
    An appeal's progress towards its goal.

    ── The number is text, not only a bar ──────────────────────────────────────

    The amounts are written out above the bar, so somebody who cannot see the
    bar — a screen-reader user, or anybody on a connection where the CSS has not
    arrived — still gets the figure. A progress bar whose only content is its
    width is a decorative div.

    ── `role="progressbar"` with real values ───────────────────────────────────

    `aria-valuenow`, `min` and `max`, plus an `aria-valuetext` that says the
    amounts rather than "68". A screen reader announcing "68 percent" without
    saying of what has told somebody almost nothing.

    ── The width is capped, the label is not ───────────────────────────────────

    An appeal that raised 140% of its goal says 140% and draws a full bar. The
    bar cannot go past its container; the sentence should not be clamped,
    because "we raised 140% of what we asked for" is the best news the page has.

    @param cause  an App\Models\Cause
--}}
@props(['cause'])

@php
    $percent = $cause->progressPercent();
    $raised = $cause->raisedAmount();
    $goal = $cause->goal;
@endphp

<div class="space-y-2">
    <p class="flex flex-wrap items-baseline justify-between gap-x-4 text-sm">
        <span class="text-lg font-semibold text-[var(--text-primary)]">
            <x-site.money :amount="$raised" />
        </span>

        @if ($goal)
            <span class="text-[var(--text-muted)]">
                {{ __('of') }} <x-site.money :amount="$goal" />
            </span>
        @endif
    </p>

    @if ($percent !== null)
        <div
            role="progressbar"
            aria-valuenow="{{ $percent }}"
            aria-valuemin="0"
            aria-valuemax="100"
            aria-valuetext="{{ __(':raised raised of a :goal goal', ['raised' => $raised->format(), 'goal' => $goal->format()]) }}"
            class="h-2 w-full overflow-hidden rounded-full bg-[var(--surface-sunken)]"
        >
            {{-- An inline width, because the value is a number computed per
                 record. There is no closed vocabulary that could express it,
                 and it is a length rather than a colour — the rule this project
                 has about stored values reaching a class attribute is about
                 colours and spacing tokens, not about arithmetic. --}}
            <div
                class="h-full rounded-full bg-[var(--brand-primary)]"
                style="width: {{ min(100, max(0, $percent)) }}%"
            ></div>
        </div>

        <p class="flex flex-wrap items-baseline justify-between gap-x-4 text-sm text-[var(--text-muted)]">
            <span>{{ __(':percent% of the goal', ['percent' => $percent]) }}</span>

            @if ($cause->donation_count > 0)
                <span>{{ trans_choice('{1}1 gift|[2,*]:count gifts', $cause->donation_count, ['count' => $cause->donation_count]) }}</span>
            @endif
        </p>
    @endif

    @php
        $endsOn = $cause->ends_on;
        $daysLeft = $endsOn?->isFuture() ? (int) now()->startOfDay()->diffInDays($endsOn->endOfDay()) : null;
    @endphp

    @if ($daysLeft !== null)
        <p class="text-sm {{ $daysLeft <= 7 ? 'font-semibold text-[var(--warning)]' : 'text-[var(--text-muted)]' }}">
            {{ trans_choice('{0}Closes today|{1}1 day left|[2,*]:count days left', $daysLeft, ['count' => $daysLeft]) }}
        </p>
    @elseif (! $cause->acceptsDonations())
        {{-- Said plainly rather than by omitting the button. Somebody following
             a link from an old newsletter deserves to know the appeal closed,
             not to wonder where the donate button went. --}}
        <p class="text-sm font-medium text-[var(--text-muted)]">{{ __('This appeal has closed.') }}</p>
    @endif
</div>
