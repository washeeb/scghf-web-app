{{--
    The donation widget.

    ── The first step of the form, not a copy of it ────────────────────────────

    Amount, how often, and Give — then the donation page, with those choices
    already made, for the name, the consent and the payment. Card details are
    never asked for here: a form that looks like it takes payment on a
    homepage and does not is exactly what a donor should distrust.

    A plain GET form, so it works with no script and the choices survive in
    the address bar if the donor shares the link.
--}}
@php
    $presets = collect($section->field('preset_amounts', []))->filter();

    if ($presets->isEmpty()) {
        $presets = collect(setting('donations.presets', []));
    }

    $presets = $presets->map(fn ($minor) => App\ValueObjects\Money::ofMinor((int) $minor, setting('donations.currency_code', 'GHS')));

    $causeId = (int) $section->field('default_cause_id', 0);
    $cause = $causeId > 0 ? App\Models\Cause::query()->whereKey($causeId)->first() : null;
    $cause = $cause?->acceptsDonations() === true ? $cause : null;

    $showFrequency = (bool) $section->field('show_frequency_toggle', true) && ($cause === null || $cause->allow_recurring);
    $id = 'dw-'.$section->getKey();
@endphp

<x-blocks.section :section="$section" :eyebrow="$section->field('eyebrow')" :heading="$section->field('heading')" :intro="$section->field('intro')">
    <form method="get" action="{{ route('donate') }}" class="rounded-[var(--radius-2xl)] border border-[var(--border)] bg-[var(--surface)] p-6 text-[var(--text-primary)] shadow-[var(--shadow-lg)] sm:p-8">
        @if ($cause !== null)
            <input type="hidden" name="cause" value="{{ $cause->slug }}">
            <p class="mb-4 text-sm text-[var(--text-secondary)]">{{ __('Giving to :appeal', ['appeal' => $cause->title]) }}</p>
        @endif

        @if ($showFrequency)
            <fieldset class="mb-5">
                <legend class="mb-2 text-sm font-semibold text-[var(--text-primary)]">{{ __('How often') }}</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (['once' => __('Just once'), 'monthly' => __('Every month')] as $value => $label)
                        <label class="cursor-pointer">
                            <input type="radio" name="frequency" value="{{ $value }}" @checked($loop->first) class="peer sr-only">
                            <span class="block rounded-full border border-[var(--border)] px-4 py-2 text-sm font-medium text-[var(--text-primary)] peer-checked:border-[var(--brand-primary)] peer-checked:bg-[var(--brand-primary)] peer-checked:text-[var(--text-on-brand)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--focus-ring)]">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endif

        <fieldset>
            <legend class="mb-2 text-sm font-semibold text-[var(--text-primary)]">{{ __('How much') }}</legend>
            <div class="flex flex-wrap gap-2">
                @foreach ($presets as $amount)
                    <label class="cursor-pointer">
                        <input type="radio" name="amount" value="{{ $amount->toMajorString() }}" @checked($loop->first) class="peer sr-only">
                        <span class="block rounded-full border border-[var(--border)] px-4 py-2 font-semibold text-[var(--text-primary)] peer-checked:border-[var(--brand-primary)] peer-checked:bg-[var(--brand-primary)] peer-checked:text-[var(--text-on-brand)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--focus-ring)]">{{ $amount->format() }}</span>
                    </label>
                @endforeach
            </div>

            <div class="mt-3">
                <label for="{{ $id }}-other" class="block text-sm text-[var(--text-secondary)]">{{ __('Or another amount (GH₵)') }}</label>
                <input
                    id="{{ $id }}-other"
                    type="number"
                    name="amount_other"
                    inputmode="decimal"
                    min="1"
                    step="0.01"
                    placeholder="0.00"
                    class="mt-1 w-40 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2 text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                    oninput="this.form.querySelectorAll('input[type=radio][name=amount]').forEach(function (r) { r.checked = false; })"
                >
                <p class="mt-1 text-xs text-[var(--text-secondary)]">{{ __('Typing an amount here replaces the one chosen above.') }}</p>
            </div>
        </fieldset>

        <button type="submit" class="btn btn-accent mt-6">{{ __('Give now') }}</button>
    </form>
</x-blocks.section>
