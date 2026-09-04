{{--
    Your details, and what we are allowed to send you.

    ── The email address is shown and not editable ─────────────────────────────

    Changing the address on an account is how a stolen session becomes a
    permanent takeover — password resets then go to an inbox the attacker
    controls. Doing it safely needs the current password, a confirmation link to
    the new address and a warning to the old one, which is a security flow and
    not a profile field. Until that exists, staff change it on request; that is
    a smaller feature than an unsafe self-service one.

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
            <h2 id="about-you" class="font-semibold text-[var(--text)]">{{ __('About you') }}</h2>

            <x-site.field
                name="name"
                :label="__('Your name')"
                :value="$user->name"
                autocomplete="name"
                required
            />

            <div class="space-y-1.5">
                <span class="block text-sm font-medium text-[var(--text)]" id="email-label">{{ __('Email address') }}</span>
                <p
                    aria-labelledby="email-label"
                    class="rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2 text-[var(--text-muted)]"
                >{{ $user->email }}</p>
                <p class="text-xs text-[var(--text-muted)]">
                    {{ __('To change this, write to us — changing it is how a stolen account becomes a permanent one, so we do it by hand.') }}
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
            <h2 id="what-we-send" class="font-semibold text-[var(--text)]">{{ __('What we send you') }}</h2>

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
            class="rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
        >{{ __('Save changes') }}</button>
    </form>
</x-site.account-layout>
