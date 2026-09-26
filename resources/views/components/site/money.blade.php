{{--
    A cedi amount, and — when the visitor or the foundation has chosen a
    second currency and there is a rate — the approximate figure in it.

    `GH₵ 150.00` stays first and stays the amount; the "≈ £8" is small
    print, hidden from screen readers only where the aria text already
    says it. The gift is charged in cedis whatever is shown here.
--}}
@props(['amount', 'approx' => true])

@php
    $display = app(App\Support\CurrencyDisplay::class);
    $second = $approx && $amount instanceof App\ValueObjects\Money ? $display->approx($amount) : null;
@endphp

<span {{ $attributes->merge(['class' => 'whitespace-nowrap']) }}>{{ $amount }}@if ($second) <span class="ml-1 text-[0.8em] font-normal text-[var(--text-muted)]" data-approx>{{ $second }}</span>@endif</span>
