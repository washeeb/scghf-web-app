{{--
    Ways to give.

    ── Seven settings, seeded in Phase 3, shown on no page until now ───────────

    Bank, branch, account name and number, SWIFT, and the Mobile Money merchant
    name and number. For a Ghanaian foundation that is not a minor omission:
    mobile money is how a very large share of giving actually happens, and a
    supporter who cannot find the merchant number gives nothing rather than
    reaching for a card.

    ── The numbers are copyable, and long ──────────────────────────────────────

    Account numbers are rendered in a monospaced run so digits line up and a
    transposed pair is visible. Somebody is typing this into a banking app on a
    phone with the page open behind it.

    ── Nothing half-filled is shown ────────────────────────────────────────────

    A block reading "Account number:" with nothing after it is worse than no
    block at all, on the one page where a visitor most needs to trust what they
    are looking at.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Ways to give')"
    :lead="__('Every gift goes to the work. Here is how to send one.')"
>
    <div class="grid gap-10 lg:grid-cols-2">
        @if ($momo)
            <section class="rounded-lg border border-[var(--border)] p-6" aria-labelledby="momo-heading">
                <h2 id="momo-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                    {{ __('Mobile Money') }}
                </h2>

                <p class="mt-2 text-sm text-[var(--text-secondary)]">
                    {{ __('The quickest way to give from Ghana.') }}
                </p>

                <dl class="mt-4 space-y-3">
                    @foreach ($momo as $label => $value)
                        <div>
                            <dt class="text-sm text-[var(--text-muted)]">{{ $label }}</dt>
                            <dd class="font-mono text-lg font-semibold tracking-wide text-[var(--text-primary)]">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if ($bank)
            <section class="rounded-lg border border-[var(--border)] p-6" aria-labelledby="bank-heading">
                <h2 id="bank-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                    {{ __('Bank transfer') }}
                </h2>

                <dl class="mt-4 space-y-3">
                    @foreach ($bank as $label => $value)
                        <div>
                            <dt class="text-sm text-[var(--text-muted)]">{{ $label }}</dt>
                            <dd class="font-mono text-lg font-semibold tracking-wide text-[var(--text-primary)]">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if (! $momo && ! $bank)
            {{-- Said plainly. A "ways to give" page with no ways on it must not
                 look like a page that failed to load. --}}
            <p class="text-[var(--text-secondary)]">
                {{ __('Our giving details are being set up. Please') }}
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('contact') }}">{{ __('get in touch') }}</a>
                {{ __('and we will tell you how to send a gift.') }}
            </p>
        @endif
    </div>

    @if ($momo || $bank)
        <p class="mt-8 max-w-2xl text-sm text-[var(--text-secondary)]">
            {{ __('Please email us after sending so we can thank you, allocate your gift and issue a receipt.') }}
            @if ($email = setting('contact.email_donations'))
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="mailto:{{ $email }}">{{ $email }}</a>
            @endif
        </p>
    @endif

    @if ($causes->isNotEmpty())
        <section class="mt-16" aria-labelledby="appeals-heading">
            <h2 id="appeals-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                {{ __('Where it could go') }}
            </h2>

            <p class="mt-1 max-w-2xl text-sm text-[var(--text-secondary)]">
                {{ __('Name an appeal in your transfer reference and we will allocate it there.') }}
            </p>

            <ul role="list" class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($causes as $cause)
                    <li><x-site.cause-card :cause="$cause" /></li>
                @endforeach
            </ul>
        </section>
    @endif
</x-site.page-shell>
