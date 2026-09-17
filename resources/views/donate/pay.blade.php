{{--
    The popup checkout.

    ── Paystack's form, over our page ──────────────────────────────────────────

    The transaction already exists — `store()` initialised it with our amount,
    our currency and our reference — and this page only resumes it from the
    access code, so nothing the browser could edit decides what is charged.
    Card details go into Paystack's own window; this site never sees them.

    ── Success is verified, not believed ───────────────────────────────────────

    `onSuccess` sends the donor to the same callback the redirect flow uses,
    which asks the gateway before saying thank you. Closing the window shows
    a plain "open it again" button; the gift stays pending until the
    reconciliation sweep writes it off, exactly as an abandoned redirect does.

    ── Works without the script ────────────────────────────────────────────────

    No JavaScript, a blocked CDN, or the fake driver: the page is a summary
    and one button to the gateway's own page. The popup is an improvement,
    not a requirement.
--}}
<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="__('Pay')">
    <div class="max-w-xl space-y-6" data-paystack-inline>
        <p class="text-lg text-[var(--text-secondary)]">
            {{ __(':amount to :cause', [
                'amount' => $transaction->amount->format(),
                'cause' => $donation->cause?->title ?? __('wherever it is needed most'),
            ]) }}
            @if ($donation->wants_recurring)
                · {{ __('every :interval', ['interval' => $donation->recurring_interval]) }}
            @endif
        </p>

        <p class="text-sm text-[var(--text-muted)]">{{ __('Reference') }} <span class="font-mono">{{ $donation->reference }}</span></p>

        <div id="paystack-closed" hidden class="rounded-md border border-[var(--border)] p-4 text-sm text-[var(--text-secondary)]">
            {{ __('The payment window was closed and nothing has been charged. You can open it again, or come back to this page later.') }}
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <a
                id="paystack-open"
                href="{{ $transaction->authorization_url }}"
                class="inline-block rounded-md bg-[var(--brand-secondary)] px-6 py-3 text-lg font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >{{ __('Open the payment window') }}</a>

            <a class="text-sm font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('donate') }}">{{ __('Change the amount') }}</a>
        </div>

        <p class="text-xs text-[var(--text-muted)]">
            {{ __('Payments are taken by Paystack. Your card details are entered on their secure form and never reach this site.') }}
        </p>
    </div>

    @if ($inline)
        @push('scripts')
            <script nonce="{{ $cspNonce ?? '' }}" src="https://js.paystack.co/v2/inline.js"></script>
            <script nonce="{{ $cspNonce ?? '' }}">
            (function () {
                if (typeof PaystackPop === 'undefined') return;

                var open = document.getElementById('paystack-open');
                var closed = document.getElementById('paystack-closed');
                var accessCode = {!! json_encode($transaction->access_code) !!};
                var callback = {!! json_encode($callbackUrl) !!};
                var reference = {!! json_encode($transaction->gateway_reference) !!};

                function resume() {
                    closed.hidden = true;
                    var popup = new PaystackPop();
                    popup.resumeTransaction(accessCode, {
                        onSuccess: function (t) {
                            window.location = callback + '?reference=' + encodeURIComponent((t && t.reference) || reference);
                        },
                        onCancel: function () {
                            closed.hidden = false;
                        },
                        onError: function () {
                            // The window could not open; the link still goes to the gateway's page.
                            closed.hidden = false;
                        }
                    });
                }

                open.addEventListener('click', function (e) {
                    e.preventDefault();
                    resume();
                });

                resume();
            })();
            </script>
        @endpush
    @endif
</x-site.page-shell>
