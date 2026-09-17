{{--
    After the donor pays — or while they are paying.

    ── ⚠ Nothing here reads the query string ───────────────────────────────────

    A donor returning from Paystack proves only that a browser followed a link.
    The money is confirmed by the signed webhook, and this page reports what the
    DATABASE says. A page that trusted `?status=success` could be made to show a
    completed gift by anybody who typed the URL, and the foundation would thank
    somebody who had paid nothing.

    ── The same page is the waiting room for a Mobile Money prompt ────────────

    A direct charge leaves the donor here while they approve the prompt on
    their phone. The page says what to do, in the network's own words where the
    gateway gave them, offers the code box when a network wants a voucher, and
    asks the server every few seconds whether the money arrived. Without a
    script it still works: a "check again" link reloads the page, and the
    status is verified with the gateway on every load.

    ── A pending gift gets an honest page ──────────────────────────────────────

    Webhooks arrive through a cron-driven queue on this host, so there is
    routinely a minute between paying and the record catching up. Saying "we are
    confirming this" is true; saying "thank you, it worked" would not yet be.
    Either way the reference is shown, so a donor who never receives a receipt
    has something to quote.
--}}
@php
    $status = $donation->status->value;
    $awaiting = $status === 'pending' ? $transaction?->awaiting_action : null;
    $isMomo = $transaction?->channel === 'mobile_money' && $transaction?->authorization_url === null;
@endphp

@if ($status === 'completed')
    {{-- The conversion, from the database, not the redirect: fired once per reference. --}}
    @push('scripts')
        <span hidden data-track-event="donation_completed" data-track-once="donation:{{ $donation->reference }}" data-track-props="{{ json_encode(['value' => round($donation->amount->toMinor() / 100, 2), 'currency' => 'GHS', 'cause' => $donation->cause?->slug, 'regular' => (bool) $donation->wants_recurring]) }}"></span>
        @if ($donation->wants_recurring)
            <span hidden data-track-event="recurring_started" data-track-once="recurring:{{ $donation->reference }}" data-track-props="{{ json_encode(['value' => round($donation->amount->toMinor() / 100, 2), 'currency' => 'GHS']) }}"></span>
        @endif
    @endpush
@endif

<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="match (true) {
        $status === 'completed' => __('Thank you'),
        in_array($status, ['failed', 'abandoned'], true) => __('That payment did not go through'),
        $awaiting === 'send_otp' => __('Enter the code from your phone'),
        $awaiting === 'pay_offline' => __('Approve the payment on your phone'),
        default => __('We are confirming your gift'),
    }"
>
    <div class="max-w-2xl space-y-6" @if ($status === 'pending') data-donation-status="{{ route('donate.status', $donation) }}" @endif>
        @if ($status === 'completed')
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('Your gift of :amount has been received. A receipt is on its way to :email.', [
                    'amount' => $donation->amount->format(),
                    'email' => $donation->donor_email,
                ]) }}
            </p>

            @if ($donation->cause && ! $donation->cause->is_general_fund)
                <p class="text-[var(--text-secondary)]">
                    {{ __('It goes to') }}
                    <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('causes.show', $donation->cause) }}">
                        {{ $donation->cause->title }}</a>.
                </p>
            @endif

            @if ($donation->wants_recurring)
                <p class="text-[var(--text-secondary)]">
                    {{ $donation->subscription_id
                        ? __('Your regular gift is set up. You can change or stop it at any time from your account.')
                        : __('We could not set up the regular gift automatically — we will be in touch to arrange it.') }}
                </p>
            @endif

            <div class="flex flex-wrap gap-3">
                @if ($receiptUrl)
                    <a href="{{ $receiptUrl }}" class="rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">
                        {{ __('Download your receipt (PDF)') }}
                    </a>
                @endif

                {{-- Sharing is a link to the appeal, not to this page: the
                     appeal is public and this page is the donor's. WhatsApp
                     first, because that is where a Ghanaian donor's friends
                     are; the copy link works everywhere else. --}}
                <a href="https://wa.me/?text={{ rawurlencode(__('I just gave to :name — you can too: ', ['name' => setting('general.short_name', config('app.name'))]).$shareUrl) }}" rel="noopener" target="_blank"
                   class="rounded-md border border-[var(--border)] px-5 py-2.5 font-semibold text-[var(--text-primary)]">
                    {{ __('Share on WhatsApp') }}
                </a>
            </div>

            {{-- The soft asks. One each, and neither is a form on this page. --}}
            <div class="grid gap-4 sm:grid-cols-2">
                @if (! $donation->wants_recurring)
                    <a href="{{ route('donate', array_filter(['cause' => $donation->cause && ! $donation->cause->is_general_fund ? $donation->cause->slug : null, 'source' => 'thanks'])) }}"
                       class="rounded-lg border border-[var(--border)] p-4 text-sm hover:border-[var(--brand-primary)]">
                        <span class="block font-semibold text-[var(--text-primary)]">{{ __('Make it monthly?') }}</span>
                        <span class="block text-[var(--text-secondary)]">{{ __('A regular gift is what lets us plan. Even a small one.') }}</span>
                    </a>
                @endif

                @guest
                    <a href="{{ route('register', ['email' => $donation->donor_email]) }}"
                       class="rounded-lg border border-[var(--border)] p-4 text-sm hover:border-[var(--brand-primary)]">
                        <span class="block font-semibold text-[var(--text-primary)]">{{ __('Keep your receipts in one place') }}</span>
                        <span class="block text-[var(--text-secondary)]">{{ __('Create an account to see every gift and download any receipt.') }}</span>
                    </a>
                @endguest
            </div>

        @elseif (in_array($status, ['failed', 'abandoned'], true))
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('Nothing has been charged. It happens — cards get declined and prompts time out for all sorts of reasons that have nothing to do with you.') }}
            </p>

            <p>
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('donate', array_filter(['amount' => $donation->amount->toMajorString(), 'cause' => $donation->cause && ! $donation->cause->is_general_fund ? $donation->cause->slug : null, 'source' => 'retry'])) }}">
                    {{ __('Try again') }}</a>
                {{ __('or') }}
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('give') }}">
                    {{ __('use Mobile Money or a bank transfer') }}</a>.
            </p>

        @elseif ($awaiting === 'send_otp')
            <p class="text-lg text-[var(--text-secondary)]">
                {{ $transaction?->display_text ?: __('Your network has sent a code to your phone. Enter it here to approve the payment.') }}
            </p>

            @error('otp')
                <p role="alert" class="rounded-md border border-[var(--danger)] px-4 py-3 text-sm text-[var(--text-primary)]">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('donate.otp', $donation) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label for="otp" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Code') }}</label>
                    <input id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="8" required
                           class="w-40 rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-lg tracking-widest text-[var(--text-primary)]">
                </div>
                <button type="submit" class="rounded-md bg-[var(--brand-primary)] px-6 py-2.5 font-semibold text-[var(--text-on-brand)]">{{ __('Approve') }}</button>
            </form>

        @elseif ($awaiting === 'pay_offline' || $isMomo)
            <p class="text-lg text-[var(--text-secondary)]">
                {{ $transaction?->display_text ?: __('Check your phone: a payment prompt of :amount has been sent to it. Enter your Mobile Money PIN to approve it.', ['amount' => $donation->amount->format()]) }}
            </p>

            <p class="text-[var(--text-secondary)]">
                {{ __('No prompt? Dial your network\'s code to see pending approvals — MTN *170#, Telecel *110#, AirtelTigo *110#. This page updates by itself once the payment is confirmed.') }}
            </p>

            <p class="text-sm">
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('donate.thanks', $donation) }}">{{ __('Check again') }}</a>
            </p>

            @if (! app()->isProduction() && Route::has('payments.fake') && $transaction)
                <p class="rounded-md border-2 border-dashed border-[var(--warning)] px-4 py-3 text-sm text-[var(--text-secondary)]">
                    {{ __('Sandbox:') }}
                    <a class="font-semibold underline" href="{{ route('payments.fake', $transaction->gateway_reference) }}">{{ __('approve or decline this prompt as the donor would on their phone') }}</a>.
                </p>
            @endif

        @else
            <p class="text-lg text-[var(--text-secondary)]">
                {{ __('Your payment is being confirmed by our provider. This usually takes under a minute, and your receipt will arrive by email once it is done.') }}
            </p>

            <p class="text-[var(--text-secondary)]">
                {{ __('You do not need to do anything, and you should not pay again.') }}
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('donate.thanks', $donation) }}">{{ __('Check again') }}</a>
            </p>
        @endif

        <p class="rounded-lg border border-[var(--border)] p-4 text-sm text-[var(--text-secondary)]">
            {{ __('Your reference is') }}
            <span class="font-mono font-semibold text-[var(--text-primary)]">{{ $donation->reference }}</span>.
            {{ __('Quote it if you need to contact us about this gift.') }}
        </p>
    </div>

    @if ($status === 'pending')
        @push('scripts')
            <script nonce="{{ $cspNonce ?? '' }}">
            (function () {
                var box = document.querySelector('[data-donation-status]');
                if (!box || !window.fetch) return;
                var url = box.getAttribute('data-donation-status');
                var tries = 0;
                function poll() {
                    if (tries++ > 60) return;
                    fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
                        .then(function (r) { return r.json(); })
                        .then(function (s) {
                            if (s.settled || s.awaiting !== {!! json_encode($awaiting) !!}) { window.location.reload(); return; }
                            setTimeout(poll, 5000);
                        })
                        .catch(function () { setTimeout(poll, 10000); });
                }
                setTimeout(poll, 4000);
            })();
            </script>
        @endpush
    @endif
</x-site.page-shell>
