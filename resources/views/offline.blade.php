{{--
    The offline page — what the service worker shows when the network is gone.

    Precached at install, so everything on it must already be in the cache or
    inline: the layout, the stylesheet, the body font. No images. The one
    thing a person offline can still do is note down the Mobile Money number
    and give when the signal comes back, so that is what the page is for.
    Every figure comes from Settings → Offline giving; a block with nothing
    behind it is not shown.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="[['label' => __('Home'), 'url' => url('/')], ['label' => __('Offline'), 'url' => null]]"
    :title="__('You are offline')"
    :lead="__('The connection has dropped. Nothing you were doing has been lost — the page will work again as soon as the signal is back.')"
>
    <div class="max-w-2xl space-y-8" data-offline-page>
        @if ($momoNumber)
            <section class="rounded-lg border border-[var(--border)] p-6" aria-labelledby="offline-momo">
                <h2 id="offline-momo" class="text-lg font-semibold text-[var(--text-primary)]">{{ __('Give by Mobile Money, whenever the signal returns') }}</h2>
                <dl class="mt-4 grid gap-3 text-[var(--text-secondary)]">
                    @if ($momoName)
                        <div><dt class="text-sm">{{ __('Merchant name') }}</dt><dd class="font-semibold text-[var(--text-primary)]">{{ $momoName }}</dd></div>
                    @endif
                    <div><dt class="text-sm">{{ __('Number') }}</dt><dd class="font-mono text-xl font-semibold tracking-wider text-[var(--text-primary)]">{{ $momoNumber }}</dd></div>
                </dl>
            </section>
        @endif

        @if ($accountNumber)
            <section class="rounded-lg border border-[var(--border)] p-6" aria-labelledby="offline-bank">
                <h2 id="offline-bank" class="text-lg font-semibold text-[var(--text-primary)]">{{ __('Or by bank transfer') }}</h2>
                <dl class="mt-4 grid gap-3 text-[var(--text-secondary)]">
                    @if ($bankName)
                        <div><dt class="text-sm">{{ __('Bank') }}</dt><dd class="font-semibold text-[var(--text-primary)]">{{ $bankName }}</dd></div>
                    @endif
                    @if ($accountName)
                        <div><dt class="text-sm">{{ __('Account name') }}</dt><dd class="font-semibold text-[var(--text-primary)]">{{ $accountName }}</dd></div>
                    @endif
                    <div><dt class="text-sm">{{ __('Account number') }}</dt><dd class="font-mono text-xl font-semibold tracking-wider text-[var(--text-primary)]">{{ $accountNumber }}</dd></div>
                </dl>
            </section>
        @endif

        @if ($phone)
            <p class="text-[var(--text-secondary)]">{{ __('Or call us:') }} <a class="font-semibold text-[var(--brand-primary)] underline" href="tel:{{ preg_replace('/\s+/', '', $phone) }}">{{ $phone }}</a></p>
        @endif

        <p>
            <a href="{{ url('/') }}" class="inline-block rounded-md bg-[var(--brand-primary)] px-6 py-3 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">{{ __('Try again') }}</a>
        </p>
    </div>
</x-site.page-shell>
