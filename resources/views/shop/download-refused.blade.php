{{--
    A download link that no longer works, explained.

    The person holding it paid for the file. They get the reason and a way
    forward, not an error code.
--}}
<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="__('This download link has stopped working')">
    <div class="max-w-xl space-y-4">
        <p class="text-lg text-[var(--text-secondary)]">{{ $reason }}</p>

        @if ($token->order)
            <p class="text-sm text-[var(--text-muted)]">
                {{ __('Order reference') }} <span class="font-mono">{{ $token->order->reference }}</span>
            </p>
        @endif

        @if ($email = setting('contact.email_shop'))
            <p class="text-[var(--text-secondary)]">
                {{ __('Write to us at') }}
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="mailto:{{ $email }}">{{ $email }}</a>
                {{ __('quoting the reference and we will send a fresh link.') }}
            </p>
        @endif
    </div>
</x-site.page-shell>
