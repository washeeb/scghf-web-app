{{--
    Password, and the record of who has been signing in.

    ── The failures are shown, not just the successes ──────────────────────────

    A run of failed attempts against your address followed by one success is the
    shape of a password that was eventually guessed. A page listing only the
    times somebody got in hides exactly the pattern worth noticing.

    `login_histories` has held all of this since Module 1 and nothing showed it
    to the person it is about. The audit trail is for accountability afterwards;
    this is for catching it while it is still happening.
--}}
<x-site.account-layout :title="__('Security')" :user="$user">

    <section class="space-y-5" aria-labelledby="change-password">
        <h2 id="change-password" class="font-semibold text-[var(--text)]">{{ __('Change your password') }}</h2>

        <form method="POST" action="{{ route('account.password.update') }}" class="max-w-md space-y-5">
            @csrf
            @method('PUT')

            <x-site.field
                name="current_password"
                type="password"
                :label="__('Current password')"
                :hint="__('Asked for so that a session left open on a shared computer cannot be used to lock you out of your own account.')"
                autocomplete="current-password"
                required
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
                class="rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
            >{{ __('Change password') }}</button>

            <p class="text-xs text-[var(--text-muted)]">
                {{ __('This signs you out on every other device, and we email you to say it happened.') }}
            </p>
        </form>
    </section>

    <section class="space-y-3 border-t border-[var(--border)] pt-8" aria-labelledby="sign-in-history">
        <h2 id="sign-in-history" class="font-semibold text-[var(--text)]">{{ __('Recent sign-ins') }}</h2>

        <p class="text-sm text-[var(--text-muted)]">
            {{ __('Anything here you do not recognise — especially a failed attempt followed by a successful one — means you should change your password now.') }}
        </p>

        @if ($history->isEmpty())
            <p class="text-sm text-[var(--text-muted)]">{{ __('Nothing recorded yet.') }}</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-[var(--border)]">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">{{ __('Your twenty most recent sign-in attempts') }}</caption>
                    <thead class="bg-[var(--surface)] text-[var(--text-muted)]">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('When') }}</th>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('Result') }}</th>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('Device') }}</th>
                            <th scope="col" class="px-4 py-3 font-medium">{{ __('Address') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                        @foreach ($history as $entry)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-[var(--text)]">
                                    {{ $entry->created_at?->format('j M Y, H:i') }}
                                </td>
                                <td class="px-4 py-3">
                                    {{--
                                        A word, not a coloured dot. Colour alone
                                        is not a signal for everybody, and this
                                        is the column the whole table is for.
                                    --}}
                                    <span @class([
                                        'font-medium',
                                        'text-[var(--text-muted)]' => $entry->outcome === App\Enums\LoginOutcome::Success,
                                        'text-[var(--brand-secondary)]' => $entry->outcome !== App\Enums\LoginOutcome::Success,
                                    ])>{{ $entry->outcome->label() }}</span>

                                    @if ($entry->is_new_device)
                                        <span class="block text-xs text-[var(--text-muted)]">{{ __('New device') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-[var(--text-muted)]">
                                    {{ collect([$entry->browser, $entry->platform])->filter()->implode(' · ') ?: __('Unknown') }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-[var(--text-muted)]">
                                    {{ $entry->ip_address ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-site.account-layout>
