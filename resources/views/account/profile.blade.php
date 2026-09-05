{{--
    Your details, and what we are allowed to send you.

    ── The address is changed by its own form, not by this one ─────────────────

    Changing the address on an account is how a stolen session becomes a
    permanent takeover — password resets then go to an inbox the attacker
    controls. So it is a separate form with the current password on it, the new
    address has to prove itself by opening a link, and the OLD address is warned
    and given a link to stop it. Until all of that happens, `email` is untouched.

    A separate `<form>` rather than a field in this one, because forms cannot
    nest and because the two have genuinely different requirements: saving a
    phone number should not ask for a password, and changing an address must.

    ── Unticking a box does something, not just displays something ─────────────

    The controller writes both the column the campaign builder reads AND a
    `marketing`-scope suppression, which is what MessageDispatcher checks on
    every message. Writing only the column would mean the box shows unticked and
    the next appeal goes out anyway.
--}}
<x-site.account-layout :title="__('Your details')" :user="$user">
    <form method="POST" action="{{ route('account.profile.update') }}" class="space-y-8">
        @csrf
        @method('PATCH')

        <section class="space-y-5" aria-labelledby="about-you">
            <h2 id="about-you" class="font-semibold text-[var(--text-primary)]">{{ __('About you') }}</h2>

            <x-site.field
                name="name"
                :label="__('Your name')"
                :value="$user->name"
                autocomplete="name"
                required
            />

            <div class="space-y-1.5">
                <span class="block text-sm font-medium text-[var(--text-primary)]" id="email-label">{{ __('Email address') }}</span>
                <p
                    aria-labelledby="email-label"
                    class="rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2 text-[var(--text-muted)]"
                >{{ $user->email }}</p>

                @if ($user->hasPendingEmailChange())
                    {{-- Shown so nobody wonders why the address above has not
                         moved. The change is not applied until the new address
                         opens its link. --}}
                    <p class="text-xs text-[var(--brand-secondary)]">
                        {{ __('Waiting for :email to confirm. Your address stays as it is until then.', ['email' => $user->pending_email]) }}
                    </p>
                @endif

                <p class="text-xs text-[var(--text-muted)]">
                    {{ __('Changing it is done below, and takes three steps on purpose.') }}
                </p>
            </div>

            <x-site.field
                name="phone"
                type="tel"
                :label="__('Mobile number')"
                :value="$user->phone_raw ?? $user->phone"
                :hint="__('Optional. Type it however you like — 024, +233, or with spaces.')"
                autocomplete="tel"
                inputmode="tel"
            />
        </section>

        <section class="space-y-3 border-t border-[var(--border)] pt-6" aria-labelledby="what-we-send">
            <h2 id="what-we-send" class="font-semibold text-[var(--text-primary)]">{{ __('What we send you') }}</h2>

            <x-site.checkbox
                name="accepts_email_marketing"
                :label="__('Email me news about the foundation and its appeals.')"
                :checked="$user->accepts_email_marketing"
            />

            <x-site.checkbox
                name="accepts_sms_marketing"
                :label="__('Text me news about the foundation and its appeals.')"
                :checked="$user->accepts_sms_marketing"
            />

            <p class="text-xs text-[var(--text-muted)]">
                {{ __('Receipts and messages about something you have done are sent either way, and carry no appeal.') }}
            </p>

            @if ($user->marketing_consent_at)
                <p class="text-xs text-[var(--text-muted)]">
                    {{ __('You agreed to hear from us on :date.', ['date' => $user->marketing_consent_at->format('j F Y')]) }}
                </p>
            @endif
        </section>

        <button
            type="submit"
            class="rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
        >{{ __('Save changes') }}</button>
    </form>

    <section class="mt-10 max-w-md space-y-5 border-t border-[var(--border)] pt-8" aria-labelledby="change-email">
        <h2 id="change-email" class="font-semibold text-[var(--text-primary)]">{{ __('Change your email address') }}</h2>

        <p class="text-sm text-[var(--text-muted)]">
            {{ __('Three steps, on purpose. We ask for your password, we send a link to the new address, and we tell the old one what is happening so you can stop it if it was not you.') }}
        </p>

        <form method="POST" action="{{ route('account.email.request') }}" class="space-y-5">
            @csrf

            <x-site.field
                name="email"
                id="new_email"
                type="email"
                :label="__('New email address')"
                autocomplete="email"
                inputmode="email"
                required
            />

            <x-site.field
                name="current_password"
                id="email_password"
                type="password"
                :label="__('Your password')"
                :hint="__('So that a session left open on a shared computer cannot be used to take the account.')"
                autocomplete="current-password"
                required
            />

            <button
                type="submit"
                class="rounded-md border border-[var(--border)] px-5 py-2.5 font-semibold text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >{{ __('Send the confirmation link') }}</button>
        </form>
    </section>
</x-site.account-layout>
