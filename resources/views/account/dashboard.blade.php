{{--
    The account overview.

    ── The empty state is not an error ─────────────────────────────────────────

    Most people who create an account here will have given nothing yet, or will
    have given under a different address. Both are ordinary, and neither should
    read as a fault: "no donations found" beside an empty table is a page
    telling somebody their money went missing.

    ── Amounts are Money objects ───────────────────────────────────────────────

    `{{ $donation->amount }}` prints `GH₵ 1,234.56` because Money stringifies to
    the display format. Never format pesewas in a template — a raw integer here
    thanks somebody for GH₵ 5,000 when they gave fifty.
--}}
<x-site.account-layout :title="__('Overview')" :user="$user">

    @if ($donor === null)
        <div class="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-6">
            <h2 class="font-semibold text-[var(--text)]">{{ __('Nothing here yet') }}</h2>

            <p class="mt-2 text-sm text-[var(--text-muted)]">
                {{ __('When you give, it will appear here. If you have given before using a different email address, tell us and we will join the two together.') }}
            </p>

            @if ($email = setting('contact.email_donations', setting('contact.email_general')))
                <p class="mt-2 text-sm">
                    <a href="mailto:{{ $email }}" class="text-[var(--brand-primary)] hover:underline">{{ $email }}</a>
                </p>
            @endif
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5">
                <p class="text-sm text-[var(--text-muted)]">{{ __('Given in total') }}</p>
                <p class="mt-1 text-2xl font-semibold text-[var(--text)]">{{ $donor->totalDonated() }}</p>
            </div>

            <div class="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5">
                <p class="text-sm text-[var(--text-muted)]">{{ __('Gifts') }}</p>
                <p class="mt-1 text-2xl font-semibold text-[var(--text)]">{{ number_format((int) $donor->donation_count) }}</p>
            </div>

            <div class="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5">
                <p class="text-sm text-[var(--text-muted)]">{{ __('First gift') }}</p>
                <p class="mt-1 text-2xl font-semibold text-[var(--text)]">
                    {{ $donor->first_donated_at?->format('M Y') ?? '—' }}
                </p>
            </div>
        </div>

        <section class="space-y-3" aria-labelledby="recent-giving">
            <h2 id="recent-giving" class="font-semibold text-[var(--text)]">{{ __('Recent giving') }}</h2>

            @if ($recent->isEmpty())
                <p class="text-sm text-[var(--text-muted)]">
                    {{ __('Nothing has completed yet. A gift appears here once the payment has been confirmed.') }}
                </p>
            @else
                {{-- Wide content scrolls inside its own container rather than
                     pushing the page sideways on a phone. --}}
                <div class="overflow-x-auto rounded-lg border border-[var(--border)]">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ __('Your five most recent completed gifts') }}</caption>
                        <thead class="bg-[var(--surface)] text-[var(--text-muted)]">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('Date') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('Towards') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('Reference') }}</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border)]">
                            @foreach ($recent as $donation)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-[var(--text)]">
                                        {{ $donation->paid_at?->format('j M Y') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-[var(--text-muted)]">
                                        {{ $donation->cause?->title ?? __('General Fund') }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-[var(--text-muted)]">
                                        {{ $donation->reference }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-[var(--text)]">
                                        {{ $donation->amount }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</x-site.account-layout>
