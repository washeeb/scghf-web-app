{{--
    Setting up the second step.

    The secret shown here is NOT yet on the account — it lives in the session
    until a code generated from it has been verified. So a page abandoned
    halfway leaves nothing behind, and a populated `two_factor_secret` always
    means a factor the person can actually produce.

    The typed key is shown alongside the QR code rather than behind a "can't
    scan?" link, because the commonest way to do this is on the phone you are
    reading the page on — and you cannot scan your own screen.
--}}
<x-site.account-layout :title="__('Two-factor authentication')" :user="auth()->user()">

    <div class="max-w-xl space-y-6">
        <p class="text-sm text-[var(--text-muted)]">
            {{ __('This adds a second step to signing in: your password, and then a code from an app on your phone. It means a stolen password is not enough on its own.') }}
        </p>

        <ol class="space-y-6">
            <li class="space-y-3">
                <h2 class="font-semibold text-[var(--text)]">{{ __('1. Scan this with your authenticator app') }}</h2>

                <p class="text-sm text-[var(--text-muted)]">
                    {{ __('Google Authenticator, Microsoft Authenticator, 1Password, Aegis — any of them.') }}
                </p>

                @if ($qrCode)
                    {{-- Inlined as a data URI rather than served from a route:
                         a URL that renders somebody's TOTP secret is one that
                         can be requested, logged by a proxy, and left in a
                         browser history. --}}
                    <img
                        src="{{ $qrCode }}"
                        alt="{{ __('QR code for setting up two-factor authentication') }}"
                        class="rounded-md border border-[var(--border)] bg-white p-2"
                        width="200"
                        height="200"
                    >
                @endif

                <div class="space-y-1">
                    <p class="text-sm text-[var(--text)]">{{ __('Or type this key in:') }}</p>
                    <p class="select-all break-all rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2 font-mono text-sm text-[var(--text)]">{{ $secret }}</p>
                    <p class="text-xs text-[var(--text-muted)]">
                        {{ __('Setting this up on the phone you are reading this on? You cannot scan your own screen — use the key.') }}
                    </p>
                </div>
            </li>

            <li class="space-y-3 border-t border-[var(--border)] pt-6">
                <h2 class="font-semibold text-[var(--text)]">{{ __('2. Enter the code it shows you') }}</h2>

                <form method="POST" action="{{ route('account.two-factor.store') }}" class="space-y-5">
                    @csrf

                    <x-site.field
                        name="code"
                        :label="__('Six-digit code')"
                        required
                        autocomplete="one-time-code"
                        inputmode="numeric"
                    />

                    <x-site.field
                        name="current_password"
                        type="password"
                        :label="__('Your password')"
                        :hint="__('Asked for because adding a second step to somebody else\'s account locks them out of it just as effectively as removing one lets an attacker in.')"
                        autocomplete="current-password"
                        required
                    />

                    <button
                        type="submit"
                        class="rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
                    >{{ __('Turn it on') }}</button>
                </form>
            </li>
        </ol>

        <p class="border-t border-[var(--border)] pt-4 text-sm">
            <a href="{{ route('account.security') }}" class="text-[var(--brand-primary)] hover:underline">{{ __('Cancel and go back') }}</a>
        </p>
    </div>
</x-site.account-layout>
