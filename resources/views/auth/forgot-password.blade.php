{{--
    Forgotten password.

    The wording is careful and the care is the security control. "If an account
    exists for that address" — never "we have sent you an email", which would
    confirm the address has an account here, and for a foundation that means
    confirming somebody is a donor.
--}}
<x-site.auth-card
    :title="__('Reset your password')"
    :subtitle="__('Give us the address on the account and we will send a link to set a new password.')"
>
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <x-site.field
            name="email"
            type="email"
            :label="__('Email address')"
            autocomplete="username"
            required
            inputmode="email"
        />

        <button
            type="submit"
            class="w-full rounded-md bg-[var(--brand-primary)] px-4 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >{{ __('Send the link') }}</button>
    </form>

    <x-slot:footer>
        <p>
            <a href="{{ route('login') }}" class="text-[var(--brand-primary)] hover:underline">{{ __('Back to sign in') }}</a>
        </p>

        {{-- Where somebody goes when the link genuinely never arrives. The form
             cannot tell them why without telling everybody else too, so a
             person has to. --}}
        @if ($support = setting('contact.email_general'))
            <p class="mt-2">
                {{ __('If nothing arrives, write to us at') }}
                <a href="mailto:{{ $support }}" class="text-[var(--brand-primary)] hover:underline">{{ $support }}</a>.
            </p>
        @endif
    </x-slot:footer>
</x-site.auth-card>
