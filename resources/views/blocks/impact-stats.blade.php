{{--
    Impact numbers.

    ── `publishedTotal()`, never `total()` ─────────────────────────────────────

    The model says plainly that `publishedTotal()` is the only method a public
    page should call: it applies the disclosure control, so a metric counting
    people whose figure falls below the minimum group size returns null rather
    than singling those people out. The foundation's categories include health,
    orphan status and widowhood, and "3 widows supported in Bongo" identifies
    them to anybody local.

    `format()` then turns null into the standard notice rather than a blank, so
    a suppressed figure reads as deliberately withheld rather than as missing
    data.

    ── The date under each figure is not decoration ────────────────────────────

    An unsourced statistic on a fundraising site is a trust risk. "4,200 people
    reached" with no date could be this year or a decade ago, and a donor who
    later finds out which will not give again.
--}}
@if ($metrics->isNotEmpty())
    <x-blocks.section :section="$section" :heading="$section->field('heading')">
        <dl class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($metrics as $metric)
                <div>
                    {{-- The figure is the thing being described, so it is the
                         definition; the name is the term. Reversing them is the
                         commonest misuse of a description list. --}}
                    <dt class="sr-only">{{ $metric->name }}</dt>
                    <dd>
                        <span class="block text-4xl font-bold tracking-tight">
                            {{ $metric->format($metric->publishedTotal()) }}
                        </span>

                        <span class="mt-1 block font-medium">{{ $metric->name }}</span>

                        @if ($section->field('show_as_of_date', true) && $metric->values_max_period_end)
                            <span class="mt-1 block text-sm opacity-75">
                                {{ __('as at :date', ['date' => \Illuminate\Support\Carbon::parse($metric->values_max_period_end)->format('F Y')]) }}
                            </span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    </x-blocks.section>
@endif
