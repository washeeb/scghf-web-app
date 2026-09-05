{{--
    Create an account.

    ── The consent boxes start empty and say what they are ─────────────────────

    Act 843 requires consent to be specific, informed and freely given. A
    pre-ticked box is none of the three, and neither is one "I agree" that
    quietly bundles marketing in with the privacy notice. So there are three
    separate boxes, two of them optional, each saying what it actually permits.

    The line about receipts is there because it is the question people ask when
    they untick everything: a receipt is transactional, it is the record of
    something they did, and it goes regardless.
--}}
<x-site.auth-card
    :title="__('Create an account')"
    :subtitle="__('So you can see your giving in one place. You do not need one to donate.')"
>
    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        {{-- A hidden field and a minimum fill time. Stops the volume bots
             without putting a CAPTCHA in front of the people least able to
             get past one. --}}
        <x-honeypot />

        <x-site.field
            name="name"
            :label="__('Your name')"
            autocomplete="name"
            required
        />

        <x-site.field
            name="email"
            type="email"
            :label="__('Email address')"
            :hint="__('We send a link here to confirm it is yours.')"
            autocomplete="email"
            required
            inputmode="email"
        />

        <x-site.field
            name="phone"
            type="tel"
            :label="__('Mobile number')"
            :hint="__('Optional. Ghanaian numbers can be typed however you like — 024, +233, or with spaces.')"
            autocomplete="tel"
            inputmode="tel"
        />

        <x-site.field
            name="password"
            type="password"
            :label="__('Password')"
            :hint="App\Support\PasswordPolicy::hint()"
            autocomplete="new-password"
            required
        />

        <x-site.field
            name="password_confirmation"
            type="password"
            :label="__('Password again')"
            autocomplete="new-password"
            required
        />

        <fieldset class="space-y-3 border-t border-[var(--border)] pt-5">
            <legend class="sr-only">{{ __('Permissions') }}</legend>

            <x-site.checkbox
                name="accepts_privacy_policy"
                :label="__('I have read how the foundation handles my personal data.')"
                required
            />

            <x-site.checkbox
                name="accepts_email_marketing"
                :label="__('Email me news about the foundation and its appeals.')"
            />

            <x-site.checkbox
                name="accepts_sms_marketing"
                :label="__('Text me news about the foundation and its appeals.')"
            />

            <p class="text-xs text-[var(--text-muted)]">
                {{ __('Receipts and messages about something you have done are sent either way, and carry no appeal. You can change any of this later.') }}
            </p>
        </fieldset>

        <button
            type="submit"
            class="w-full rounded-md bg-[var(--brand-primary)] px-4 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >{{ __('Create my account') }}</button>
    </form>

    <x-slot:footer>
        <p>
            {{ __('Already have an account?') }}
            <a href="{{ route('login') }}" class="text-[var(--brand-primary)] hover:underline">{{ __('Sign in') }}</a>.
        </p>
    </x-slot:footer>
</x-site.auth-card>
