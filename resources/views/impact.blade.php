{{--
    What the foundation has actually done.

    ── Raised and disbursed, side by side ──────────────────────────────────────

    Publishing "raised" alone is the number every charity publishes, and it
    answers nothing a sceptical donor is asking. Publishing what went OUT beside
    it is the claim that can be checked, and it is why this page exists.

    The two will not match, and should not: money raised in December is spent in
    March, and a reserve is prudence rather than hoarding. The page says so
    rather than leaving somebody to wonder.

    ── A withheld metric is absent, not dashed ─────────────────────────────────

    `publishedTotal()` returns null for a figure computed from a group too small
    to publish, and those are dropped entirely. A dash invites somebody to ask
    what the number was.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Our impact')"
    :lead="__('What has been given, what has been spent, and what it changed.')"
>
    <dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-[var(--border)] p-5">
            <dt class="text-sm text-[var(--text-muted)]">{{ __('Raised') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ $raised->format() }}</dd>
        </div>

        <div class="rounded-lg border border-[var(--border)] p-5">
            <dt class="text-sm text-[var(--text-muted)]">{{ __('Paid out to the work') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ $disbursed->format() }}</dd>
        </div>

        <div class="rounded-lg border border-[var(--border)] p-5">
            <dt class="text-sm text-[var(--text-muted)]">{{ __('Supporters') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ number_format($donors) }}</dd>
        </div>

        <div class="rounded-lg border border-[var(--border)] p-5">
            <dt class="text-sm text-[var(--text-muted)]">{{ __('Projects') }}</dt>
            <dd class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ number_format($projects) }}</dd>
        </div>

        @if ($volunteerHours > 0 || $volunteers > 0)
            <div class="rounded-lg border border-[var(--border)] p-5">
                <dt class="text-sm text-[var(--text-muted)]">{{ __('Volunteer hours given') }}</dt>
                <dd class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ number_format($volunteerHours) }}</dd>
                <dd class="mt-1 text-xs text-[var(--text-muted)]">{{ trans_choice(':count volunteer today|:count volunteers today', $volunteers) }}</dd>
            </div>
        @endif
    </dl>

    <p class="mt-4 max-w-2xl text-sm text-[var(--text-muted)]">
        {{ __('Raised and paid out will not match, and should not: money given in one month is spent over the ones that follow, and holding a reserve is prudence rather than hoarding.') }}
    </p>

    @if ($metrics->isNotEmpty())
        <section class="mt-14" aria-labelledby="metrics-heading">
            <h2 id="metrics-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                {{ __('What that paid for') }}
            </h2>

            <dl class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($metrics as $metric)
                    <div class="rounded-lg border border-[var(--border)] p-5">
                        <dt class="text-sm text-[var(--text-muted)]">{{ $metric['name'] }}</dt>
                        <dd class="mt-1 text-2xl font-bold text-[var(--text-primary)]">{{ $metric['value'] }}</dd>

                        @if ($metric['description'])
                            <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ $metric['description'] }}</p>
                        @endif
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($regions->isNotEmpty())
        <section class="mt-14" aria-labelledby="regions-heading">
            <h2 id="regions-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                {{ __('Where we work') }}
            </h2>

            {{-- A table rather than a chart. It is a short list of names and
                 numbers, it is readable by a screen reader without any extra
                 work, and it costs nothing to download. --}}
            <table class="mt-6 w-full max-w-md text-left text-sm">
                <caption class="sr-only">{{ __('Published projects by region') }}</caption>
                <thead>
                    <tr class="border-b border-[var(--border)]">
                        <th scope="col" class="py-2 font-semibold text-[var(--text-primary)]">{{ __('Region') }}</th>
                        <th scope="col" class="py-2 text-right font-semibold text-[var(--text-primary)]">{{ __('Projects') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($regions as $row)
                        <tr class="border-b border-[var(--border)]">
                            <td class="py-2 text-[var(--text-secondary)]">
                                <a class="hover:text-[var(--brand-primary)] hover:underline" href="{{ route('projects.index', ['region' => $row['region']]) }}">
                                    {{ $row['region'] }}
                                </a>
                            </td>
                            <td class="py-2 text-right text-[var(--text-primary)]">{{ number_format($row['projects']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <p class="mt-14 max-w-2xl text-[var(--text-secondary)]">
        {{ __('Our annual reports and financial statements are published in full.') }}
        <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('documents.index') }}">
            {{ __('Read them here.') }}
        </a>
    </p>
</x-site.page-shell>
