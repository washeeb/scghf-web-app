{{--
    "Show amounts also in…" — the visitor's second currency.

    A plain form that posts and reloads; a few lines in app.js submit it
    on change and hide the button (no inline handler — the CSP has no
    'unsafe-inline'). The choice lands in a cookie the page cache keys on,
    so the next page carries it. Shown only
    when the foundation has a rate to show; nothing on the site is charged
    in anything but cedis, and the label says so.
--}}
@php
    $display = app(App\Support\CurrencyDisplay::class);
    $active = $display->active();
    $hasRates = app(App\Support\ExchangeRates::class)->current() !== null;
@endphp

@if ($hasRates)
    <form method="POST" action="{{ route('currency.set') }}" class="flex flex-wrap items-center gap-2" data-currency-picker>
        @csrf
        <input type="hidden" name="return" value="{{ url()->current() }}">
        <label for="currency-picker" class="text-sm text-[var(--text-muted)]">{{ __('Also show amounts in') }}</label>
        <select
            id="currency-picker"
            name="currency"
            class="rounded-md border border-[var(--border)] bg-[var(--surface)] px-2 py-1.5 text-sm text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >
            @foreach (App\Support\CurrencyDisplay::options() as $code => $label)
                <option value="{{ $code === '' ? 'none' : $code }}" @selected(($active ?? '') === $code)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded-md border border-[var(--border)] px-2 py-1.5 text-sm text-[var(--text-primary)]" data-currency-apply>{{ __('Apply') }}</button>
        @if ($active)
            <span class="text-xs text-[var(--text-muted)]">{{ __('Approximate; gifts are taken in cedis.') }}</span>
        @endif
    </form>
@endif
