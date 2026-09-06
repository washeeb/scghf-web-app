{{--
    After the donor comes back from the gateway.

    ── ⚠ Nothing here reads the query string ───────────────────────────────────

    A donor returning from Paystack proves only that a browser followed a link.
    The money is confirmed by the signed webhook, and this page reports what the
    DATABASE says. A page that trusted `?status=success` could be made to show a
    completed gift by anybody who typed the URL, and the foundation would thank
    somebody who had paid nothing.

    ── A pending gift gets an honest page ──────────────────────────────────────

    Webhooks arrive through a cron-driven queue on this host, so there is
    routinely a minute between paying and the record catching up. Saying "we are
    confirming this" is true; saying "thank you, it worked" would not yet be.
    Either way the reference is shown, so a donor who never receives a receipt
    has something to quote.
--}}
@php
    $status = $donation->status->value;
@endphp

<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="match ($status) {
        'completed' => __('Thank you'),
        'failed', 'abandoned' => __('That payment did not go through'),
        default => __('We are confirming your gift'),
    }"
>
    <div class="max-w-2xl space-y-6">
        @if ($status === 'completed')
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('Your gift of :amount has been received. A receipt is on its way to :email.', [
                    'amount' => $donation->amount->format(),
                    'email' => $donation->donor_email,
                ]) }}
            </p>

            @if ($donation->cause)
                <p class="text-[var(--text-secondary)]">
                    {{ __('It goes to') }}
                    <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('causes.show', $donation->cause) }}">
                        {{ $donation->cause->title }}</a>.
                </p>
            @endif

            @if ($donation->wants_recurring)
                <p class="text-[var(--text-secondary)]">
                    {{ $donation->subscription_id
                        ? __('Your monthly gift is set up. You can stop it at any time.')
                        : __('We could not set up the monthly gift automatically — we will be in touch to arrange it.') }}
                </p>
            @endif

        @elseif (in_array($status, ['failed', 'abandoned'], true))
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('Nothing has been charged. It happens — cards get declined for all sorts of reasons that have nothing to do with you.') }}
            </p>

            <p>
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('donate') }}">
                    {{ __('Try again') }}</a>
                {{ __('or') }}
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('give') }}">
                    {{ __('use Mobile Money or a bank transfer') }}</a>.
            </p>

        @else
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('Your payment is being confirmed by our provider. This usually takes under a minute, and your receipt will arrive by email once it is done.') }}
            </p>

            <p class="text-[var(--text-secondary)]">
                {{ __('You do not need to do anything, and you should not pay again.') }}
            </p>
        @endif

        <p class="rounded-lg border border-[var(--border)] p-4 text-sm text-[var(--text-secondary)]">
            {{ __('Your reference is') }}
            <span class="font-mono font-semibold text-[var(--text-primary)]">{{ $donation->reference }}</span>.
            {{ __('Quote it if you need to contact us about this gift.') }}
        </p>
    </div>
</x-site.page-shell>
