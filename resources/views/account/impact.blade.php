{{--
    Your impact: the donor's gifts and what followed them, newest first.

    Three kinds of entry (see App\Donors\ImpactTimeline): a gift, an update
    published on an appeal after they gave to it, and the public figures for
    the project since their first gift. The figures say "since", not
    "because": the foundation does not claim one gift did all of it.

    A timeline is a list. `<ol>` with the newest first is what a screen
    reader announces as a numbered sequence, which is what it is.
--}}
<x-site.account-layout :title="__('Your impact')" :user="$user">

    @if ($donor === null || $entries->isEmpty())
        <div class="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-6">
            <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Your story starts with a gift') }}</h2>
            <p class="mt-2 text-sm text-[var(--text-muted)]">
                {{ __('Once a gift has completed, it appears here — followed by the updates the foundation publishes on that work and the figures it reports.') }}
            </p>
            <p class="mt-4">
                <a href="{{ route('donate') }}" class="inline-block rounded-md bg-[var(--brand-primary)] px-5 py-2.5 text-sm font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">{{ __('Give now') }}</a>
            </p>
        </div>
    @else
        <p class="text-sm text-[var(--text-muted)]">
            {{ __('Every completed gift, and what the foundation published about that work afterwards. Figures are the ones reported publicly, counted from your first gift.') }}
        </p>

        <ol class="relative space-y-6 border-l border-[var(--border)] pl-6" data-impact-timeline>
            @foreach ($entries as $entry)
                <li class="relative">
                    <span @class([
                        'absolute -left-[1.85rem] top-1.5 block h-3 w-3 rounded-full ring-4 ring-[var(--bg)]',
                        'bg-[var(--brand-primary)]' => $entry['kind'] === 'gift',
                        'bg-[var(--brand-secondary)]' => $entry['kind'] === 'update',
                        'bg-[var(--text-muted)]' => $entry['kind'] === 'figures',
                    ]) aria-hidden="true"></span>

                    <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">
                        <time datetime="{{ $entry['at']->toDateString() }}">{{ $entry['at']->format('j F Y') }}</time>
                        · {{ match ($entry['kind']) { 'gift' => __('Your gift'), 'update' => __('Update'), default => __('Figures') } }}
                    </p>

                    <h2 class="mt-1 font-semibold text-[var(--text-primary)]">
                        @if ($entry['url'])
                            <a href="{{ $entry['url'] }}" class="hover:text-[var(--brand-primary)] hover:underline">{{ $entry['title'] }}</a>
                        @else
                            {{ $entry['title'] }}
                        @endif
                    </h2>

                    @if ($entry['body'])
                        <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $entry['body'] }}</p>
                    @endif

                    @if ($entry['figures'] !== [])
                        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                            @foreach ($entry['figures'] as $figure)
                                <div class="rounded-lg border border-[var(--border)] bg-[var(--surface)] px-4 py-3">
                                    <dt class="text-sm text-[var(--text-muted)]">{{ $figure['label'] }}</dt>
                                    <dd class="text-xl font-semibold text-[var(--text-primary)]">{{ $figure['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</x-site.account-layout>
