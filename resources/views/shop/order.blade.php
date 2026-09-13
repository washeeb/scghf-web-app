{{--
    The order, as the database has it.

    ── ⚠ Nothing here reads the query string ───────────────────────────────────

    A customer returning from Paystack proves only that a browser followed a
    link. The order is marked paid by the signed webhook, and this page reports
    what the DATABASE says. A page that trusted `?status=success` could be made
    to show a paid order to anybody who typed the URL, and the foundation would
    post a parcel for money it never received.

    ── A pending order gets an honest page ─────────────────────────────────────

    Webhooks arrive through a cron-driven queue on this host, so there is
    routinely a minute between paying and the record catching up. Saying "we
    are confirming this" is true; "thank you, it is on its way" would not be.
    Either way the reference is shown.
--}}
@php
    $status = $order->status;
@endphp

<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="match (true) {
        $status->isPaid() => __('Thank you for your order'),
        $status->value === 'cancelled' => __('This order was not completed'),
        $status->value === 'needs_review' => __('We are checking this order'),
        default => __('We are confirming your payment'),
    }"
>
    <div class="max-w-2xl space-y-6">
        @error('payment')
            <p role="alert" class="rounded-md border border-[var(--danger)] px-4 py-3 text-sm text-[var(--text-primary)]">{{ $message }}</p>
        @enderror

        @if ($status->isPaid())
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('We have received your payment of :amount. A confirmation is on its way to :email.', [
                    'amount' => $order->total->format(),
                    'email' => $order->customer_email,
                ]) }}
            </p>

            <p class="text-[var(--text-secondary)]">
                {{ $order->is_pickup
                    ? __('We will let you know when it is ready to collect.')
                    : __('We will let you know when it has been dispatched.') }}
                @if ($order->hasDigitalItems())
                    {{ __('Download links are in the confirmation email.') }}
                @endif
            </p>

        @elseif ($status->value === 'cancelled')
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('Nothing has been charged and the items have gone back on the shelf. It happens — cards get declined for all sorts of reasons that have nothing to do with you.') }}
            </p>

            <p>
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('shop.index') }}">{{ __('Back to the shop') }}</a>
            </p>

        @elseif ($status->value === 'needs_review')
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('The payment we received did not match the order exactly, so a person is looking at it. We will be in touch — you do not need to do anything, and you should not pay again.') }}
            </p>

        @else
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('Your payment is being confirmed by our provider. This usually takes under a minute, and your confirmation will arrive by email once it is done.') }}
            </p>

            <p class="text-[var(--text-secondary)]">{{ __('You do not need to do anything, and you should not pay again.') }}</p>

            @if ($retryUrl)
                <p class="text-sm text-[var(--text-secondary)]">
                    {{ __('If you left the payment page before paying, you can') }}
                    <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ $retryUrl }}">{{ __('return to it') }}</a>.
                </p>
            @endif
        @endif

        <div class="rounded-lg border border-[var(--border)] p-4 text-sm">
            <p class="text-[var(--text-secondary)]">
                {{ __('Your order reference is') }}
                <span class="font-mono font-semibold text-[var(--text-primary)]">{{ $order->reference }}</span>.
                {{ __('Quote it if you need to contact us about this order.') }}
            </p>

            <ul role="list" class="mt-4 divide-y divide-[var(--border)] border-t border-[var(--border)]">
                @foreach ($order->items as $item)
                    <li class="flex justify-between gap-4 py-2">
                        <span class="text-[var(--text-primary)]">
                            {{ $item->quantity }} × {{ $item->product_name }}@if ($item->variant_name) — {{ $item->variant_name }}@endif
                        </span>
                        <span class="font-semibold text-[var(--text-primary)]">{{ $item->line_total->format() }}</span>
                    </li>
                @endforeach
            </ul>

            <dl class="mt-3 space-y-1 border-t border-[var(--border)] pt-3">
                <div class="flex justify-between gap-4"><dt class="text-[var(--text-secondary)]">{{ __('Subtotal') }}</dt><dd class="text-[var(--text-primary)]">{{ $order->subtotal->format() }}</dd></div>
                @if ($order->discount->isPositive())
                    <div class="flex justify-between gap-4"><dt class="text-[var(--text-secondary)]">{{ __('Discount') }}</dt><dd class="text-[var(--text-primary)]">− {{ $order->discount->format() }}</dd></div>
                @endif
                @if ($order->requiresDelivery())
                    <div class="flex justify-between gap-4"><dt class="text-[var(--text-secondary)]">{{ $order->is_pickup ? __('Collection') : __('Delivery') }}@if ($order->shipping_method) ({{ $order->shipping_method }})@endif</dt><dd class="text-[var(--text-primary)]">{{ $order->shipping->isZero() ? __('free') : $order->shipping->format() }}</dd></div>
                @endif
                @if ($order->donation->isPositive())
                    <div class="flex justify-between gap-4"><dt class="text-[var(--text-secondary)]">{{ __('Your gift') }}</dt><dd class="text-[var(--text-primary)]">{{ $order->donation->format() }}</dd></div>
                @endif
                <div class="flex justify-between gap-4 font-semibold"><dt class="text-[var(--text-primary)]">{{ __('Total') }}</dt><dd class="text-[var(--text-primary)]">{{ $order->total->format() }}</dd></div>
            </dl>

            @if ($order->is_pickup && $order->shippingZone?->pickup_address)
                <p class="mt-3 border-t border-[var(--border)] pt-3 text-[var(--text-secondary)]">
                    {{ __('Collect from') }}: {{ $order->shippingZone->pickup_address }}@if ($order->shippingZone->pickup_hours) · {{ $order->shippingZone->pickup_hours }}@endif
                </p>
            @elseif (! $order->is_pickup && $order->requiresDelivery())
                <p class="mt-3 border-t border-[var(--border)] pt-3 text-[var(--text-secondary)]">
                    {{ __('Delivering to') }}: {{ $order->deliveryAddressLine() }}
                </p>
            @endif
        </div>

        <p class="text-xs text-[var(--text-muted)]">
            {{ __('A purchase is not a donation. No charitable receipt is issued for goods, and none is needed — the whole of what the shop makes goes to the foundation\'s work. A gift made through the shop is receipted separately.') }}
        </p>
    </div>
</x-site.page-shell>
