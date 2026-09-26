{{--
    Set a new password, from the link in the email.

    The address is shown and readonly rather than hidden. The broker validates
    the token against it, so it has to travel with the form — and a person who
    holds two accounts needs to see which one they are about to change the
    password on. A hidden field would let them set the wrong one and never know.

    It is still submitted as a normal field, so a tampered value simply fails
    token validation. Readonly is for the reader, not for security.
--}}
<x-site.auth-card :title="__('Set a new password')">
    <form method="POST" action="{{ route('password.store') }}" class="space-y-5">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <x-site.field
            name="email"
            type="email"
            :label="__('Email address')"
            :value="$email"
            autocomplete="username"
            required
            readonly
            class="w-full rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2 text-[var(--text-muted)]"
        />

        <x-site.field
            name="password"
            type="password"
            :label="__('New password')"
            :hint="App\Support\PasswordPolicy::hint()"
            autocomplete="new-password"
            required
        />

        <x-site.field
            name="password_confirmation"
            type="password"
            :label="__('New password again')"
            autocomplete="new-password"
            required
        />

        <button
            type="submit"
            class="w-full rounded-md bg-[var(--brand-primary)] px-4 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >{{ __('Save the new password') }}</button>
    </form>
</x-site.auth-card>
