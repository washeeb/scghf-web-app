{{--
    Sign in.

    Staff do not sign in here — see App\Http\Controllers\Auth\LoginController for
    why a public form that accepted them would be a bypass of the admin panel's
    mandatory second factor. Nothing on this page says so, deliberately: which
    addresses are staff addresses is not something a sign-in form tells people
    who have not proved they hold one.
--}}
<x-site.auth-card
    :title="__('Sign in')"
    :subtitle="__('To see your giving, manage a regular gift, or change what we send you.')"
>
    <form method="POST" action="{{ route('login') }}" class="space-y-5">
        @csrf

        <x-site.field
            name="email"
            type="email"
            :label="__('Email address')"
            autocomplete="username"
            required
            inputmode="email"
        />

        <x-site.field
            name="password"
            type="password"
            :label="__('Password')"
            autocomplete="current-password"
            required
        />

        <div class="flex items-center justify-between gap-4">
            <x-site.checkbox
                name="remember"
                :label="__('Stay signed in')"
                :hint="__('Not on a shared or public computer.')"
            />

            <a
                href="{{ route('password.request') }}"
                class="shrink-0 text-sm text-[var(--brand-primary)] hover:underline"
            >{{ __('Forgotten it?') }}</a>
        </div>

        <button
            type="submit"
            class="w-full rounded-md bg-[var(--brand-primary)] px-4 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >{{ __('Sign in') }}</button>
    </form>

    <x-slot:footer>
        @if (config('security.accounts.registration_open', true))
            <p>
                {{ __('No account yet?') }}
                <a href="{{ route('register') }}" class="text-[var(--brand-primary)] hover:underline">{{ __('Create one') }}</a>.
            </p>
        @endif

        {{--
            An account is not required to give, and saying so here matters: a
            sign-in wall in front of a donation is the fastest way to lose the
            donation. The account is for looking at giving afterwards.
        --}}
        <p class="mt-2">{{ __('You do not need an account to donate.') }}</p>
    </x-slot:footer>
</x-site.auth-card>
