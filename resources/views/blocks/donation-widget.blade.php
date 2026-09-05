{{--
    The donation form.

    ── A placeholder that says so ──────────────────────────────────────────────

    The form itself is Phase 6, and this renders the heading, the preset amounts
    and a button that goes to the donation page. It is deliberately not a
    non-functional form: a donation form that looks real and does nothing is
    worse than a link, because somebody will type their card details into it.
--}}
@php
    $presets = collect($section->field('preset_amounts', []))->filter();

    if ($presets->isEmpty()) {
        $presets = collect(setting('donations.presets', []));
    }
@endphp

<x-blocks.section :section="$section" :heading="$section->field('heading')" :intro="$section->field('intro')">
    <div class="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-6">
        @if ($presets->isNotEmpty())
            <ul class="flex flex-wrap gap-2">
                @foreach ($presets as $amount)
                    <li class="rounded-md border border-[var(--border)] px-4 py-2 font-semibold text-[var(--text)]">
                        {{ App\ValueObjects\Money::ofMinor((int) $amount, setting('donations.currency_code', 'GHS')) }}
                    </li>
                @endforeach
            </ul>
        @endif

        <a
            href="{{ url('/donate') }}"
            class="mt-6 inline-block rounded-md bg-[var(--brand-secondary)] px-6 py-3 font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
        >{{ __('Give now') }}</a>
    </div>
</x-blocks.section>
