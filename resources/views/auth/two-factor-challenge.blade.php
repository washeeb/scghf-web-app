{{--
    The second step.

    Nobody is signed in while this page is open — the password has been checked
    and the account deliberately has not been authenticated. See
    App\Http\Controllers\Auth\TwoFactorChallengeController.

    One field for both a code and a recovery code, because asking somebody who
    has just lost their phone to first find the right form is a bad moment to
    add a step, and the two are trivially distinguishable by shape.
--}}
<x-site.auth-card
    :title="__('One more step')"
    :subtitle="__('Enter the six-digit code from your authenticator app.')"
>
    <form method="POST" action="{{ route('two-factor.challenge') }}" class="space-y-5">
        @csrf

        <x-site.field
            name="code"
            :label="__('Code')"
            :hint="__('Or one of your recovery codes, if you do not have your phone.')"
            required
            {{-- `one-time-code` is what lets a phone offer the code from the
                 notification rather than making somebody switch apps and
                 memorise six digits. --}}
            autocomplete="one-time-code"
            inputmode="text"
            autofocus
        />

        <button
            type="submit"
            class="w-full rounded-md bg-[var(--brand-primary)] px-4 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
        >{{ __('Continue') }}</button>
    </form>

    <x-slot:footer>
        @if ($recoveryCodesLeft === 0)
            {{-- Worth saying plainly. Somebody with no phone and no codes left
                 needs a person, and finding that out after three failed
                 attempts is worse than being told now. --}}
            <p class="text-[var(--brand-secondary)]">
                {{ __('You have no recovery codes left. If you cannot use your authenticator app, contact us.') }}
            </p>
        @endif

        @if ($support = setting('contact.email_general'))
            <p class="mt-2">
                {{ __('Locked out?') }}
                <a href="mailto:{{ $support }}" class="text-[var(--brand-primary)] hover:underline">{{ $support }}</a>
            </p>
        @endif

        <p class="mt-2">
            <a href="{{ route('login') }}" class="text-[var(--brand-primary)] hover:underline">{{ __('Start again') }}</a>
        </p>
    </x-slot:footer>
</x-site.auth-card>
