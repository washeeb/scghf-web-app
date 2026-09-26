{{--
    Your data: a copy of it, and the end of the account.

    Both behind the password, because this page in the wrong hands is either
    a complete dossier or a way to be spiteful. The deletion says plainly
    what is kept and why — a donor who gave last year has a receipt the
    foundation must be able to account for, and pretending otherwise would
    be a promise the law does not let us keep.
--}}
<x-site.account-layout :title="__('Your data')" :user="$user">

    <section class="space-y-4" aria-labelledby="export-heading">
        <h2 id="export-heading" class="font-semibold text-[var(--text-primary)]">{{ __('A copy of your data') }}</h2>
        <p class="max-w-lg text-sm text-[var(--text-secondary)]">
            {{ __('Everything we hold about you, as a file you can open or pass on: your details, your giving, orders, event registrations, volunteer applications, newsletter settings, the consents you gave and when, and where your account has been signed in from. Other people named in your records — a referee, somebody a gift was in memory of — are not included, because their details are theirs.') }}
        </p>
        <form method="POST" action="{{ route('account.privacy.export') }}" class="max-w-md space-y-4">
            @csrf
            <x-site.field name="current_password" type="password" :label="__('Your password')" required autocomplete="current-password" />
            <button type="submit" class="rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">{{ __('Download my data') }}</button>
        </form>
    </section>

    <section class="space-y-4 rounded-lg border border-[var(--danger)] p-5" aria-labelledby="delete-heading">
        <h2 id="delete-heading" class="font-semibold text-[var(--text-primary)]">{{ __('Delete your account') }}</h2>
        <div class="max-w-lg space-y-2 text-sm text-[var(--text-secondary)]">
            <p>{{ __('This ends your account now. You will be signed out everywhere, your name, address and phone number are removed, your newsletter subscription is cancelled, and your email address is blocked from being added back by any form on this site.') }}</p>
            <p>{{ __('What we have to keep: records of donations and orders, and the receipts for them, for six years, because they are accounting records the law requires us to hold. They stay without your name on them. If you would rather we held nothing at all, write to us and we will explain what the rules allow.') }}</p>
        </div>
        <form method="POST" action="{{ route('account.privacy.destroy') }}" class="max-w-md space-y-4">
            @csrf
            @method('DELETE')
            <x-site.field name="current_password" type="password" :label="__('Your password')" required autocomplete="current-password" />
            <x-site.checkbox name="confirm" required :label="__('I understand this cannot be undone.')" />
            <button type="submit" class="rounded-md border border-[var(--danger)] px-5 py-2.5 font-semibold text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">{{ __('Delete my account') }}</button>
        </form>
    </section>
</x-site.account-layout>
