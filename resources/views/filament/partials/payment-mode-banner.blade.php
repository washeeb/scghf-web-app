{{--
    Test mode versus live mode, on every admin page.

    Live says nothing: a permanent "everything is fine" strip trains people to
    stop reading strips. Anything else gets a band nobody can miss, so a
    trustee never reports sandbox gifts as income.
--}}
@unless ($mode->isLive())
    <div
        role="status"
        data-payment-mode="{{ $mode->value }}"
        @class([
            'flex flex-wrap items-center justify-center gap-x-2 gap-y-1 px-4 py-1.5 text-center text-sm font-medium',
            'bg-amber-400 text-amber-950' => $mode === \App\Payments\PaymentMode::Test,
            'bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-950' => $mode === \App\Payments\PaymentMode::Fake,
        ])
    >
        <span class="inline-flex items-center rounded-full border border-current px-2 py-0.5 text-xs font-bold uppercase tracking-wide">
            {{ $mode->label() }}
        </span>
        <span>{{ $mode->explanation() }}</span>
    </div>
@endunless
