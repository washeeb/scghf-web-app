{{--
    "Check your inbox."

    Reached after registering, and by anybody unverified who tries to open their
    giving history. It explains what verification is actually for here, because
    "confirm your email" reads as bureaucracy unless somebody says why: the
    giving history is matched on the email address, so proving the address is
    what earns the history.
--}}
<x-site.auth-card
    :title="__('Confirm your email address')"
    :subtitle="__('We have sent a link to :email.', ['email' => auth()->user()?->email])"
>
    <div class="space-y-4 text-sm text-[var(--text-muted)]">
        <p>{{ __('Open it and your account is ready. The link expires, so if it has been a while, ask for another one below.') }}</p>

        <p>{{ __('Your giving history stays hidden until the address is confirmed — it is matched on your email address, and we will not show it to somebody who has only typed the address in.') }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button
                type="submit"
                class="rounded-md bg-[var(--brand-primary)] px-4 py-2.5 text-sm font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
            >{{ __('Send another link') }}</button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button
                type="submit"
                class="rounded-md border border-[var(--border)] px-4 py-2.5 text-sm font-semibold text-[var(--text)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
            >{{ __('Sign out') }}</button>
        </form>
    </div>

    <x-slot:footer>
        {{-- Deliberately reachable from here. Somebody who suspects the account
             was created by somebody else needs to change the password, and that
             must not require verifying an address they may not control. --}}
        <p>
            {{ __('Typed the wrong address, or did not create this account?') }}
            <a href="{{ route('account.security') }}" class="text-[var(--brand-primary)] hover:underline">{{ __('Check your account security') }}</a>.
        </p>
    </x-slot:footer>
</x-site.auth-card>
