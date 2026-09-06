{{--
    The sandbox checkout.

    ⚠ Never registered in production — the route is not defined there and the
    controller aborts as well. This page can mark a payment successful without
    money changing hands, which makes it the single most dangerous file in this
    repository if it ever reaches the live site.

    It exists because `FakeGateway` is the DEFAULT driver, and its authorization
    URL pointed at a route nobody had built: the donation engine was fully
    tested and the donation journey could not be walked once, by anybody.

    Pressing a button here delivers a real signed webhook through the real
    handler — signature check, raw event store, idempotency and queued
    processing included. That is the point of a fake gateway rather than a mock.
--}}
<x-layouts.app :meta="$meta">
    <div class="mx-auto max-w-lg px-4 py-16">
        <div class="rounded-lg border-2 border-dashed border-[var(--warning)] p-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--warning)]">
                {{ __('Sandbox — no real money') }}
            </p>

            <h1 class="mt-2 text-2xl font-bold text-[var(--text-primary)]">
                {{ __('Test payment') }}
            </h1>

            <dl class="mt-6 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-[var(--text-muted)]">{{ __('Amount') }}</dt>
                    <dd class="font-semibold text-[var(--text-primary)]">{{ $transaction->amount->format() }}</dd>
                </div>

                <div class="flex justify-between gap-4">
                    <dt class="text-[var(--text-muted)]">{{ __('Reference') }}</dt>
                    <dd class="font-mono text-[var(--text-primary)]">{{ $transaction->gateway_reference }}</dd>
                </div>

                @if ($transaction->customer_email)
                    <div class="flex justify-between gap-4">
                        <dt class="text-[var(--text-muted)]">{{ __('Email') }}</dt>
                        <dd class="text-[var(--text-primary)]">{{ $transaction->customer_email }}</dd>
                    </div>
                @endif
            </dl>

            <form method="POST" action="{{ route('payments.fake.pay', $transaction->gateway_reference) }}" class="mt-8 space-y-3">
                @csrf

                <button
                    type="submit"
                    name="outcome"
                    value="success"
                    class="w-full rounded-md bg-[var(--brand-primary)] px-4 py-3 font-semibold text-[var(--text-on-brand)]"
                >{{ __('Pay successfully') }}</button>

                {{-- The failure path matters as much as the success one. A
                     foundation needs to know what a declined card looks like to
                     the person holding it, and that is not something to
                     discover from a real donor's complaint. --}}
                <button
                    type="submit"
                    name="outcome"
                    value="fail"
                    class="w-full rounded-md border border-[var(--border)] px-4 py-3 font-semibold text-[var(--text-secondary)]"
                >{{ __('Simulate a declined card') }}</button>
            </form>
        </div>
    </div>
</x-layouts.app>
