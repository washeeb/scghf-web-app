{{--
    One appeal.

    ── The donor wall carries names and no amounts ─────────────────────────────

    `publicDonorName()` returns "Anonymous" for a gift marked so — but the
    amount is a separate question. A wall showing "Anonymous — GH₵ 5,000" beside
    named gifts identifies the anonymous donor to anybody who knows what they
    gave, which is precisely the person they were hiding it from.

    ── A closed appeal keeps its page ──────────────────────────────────────────

    It is the record of what was raised and what it did, and deleting it turns
    every link anybody ever shared into a 404.
--}}
<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="$cause->title" :lead="$cause->summary">
    <div class="grid gap-12 lg:grid-cols-[2fr_1fr]">
        <div class="max-w-3xl space-y-10">
            @if ($cause->featuredImage)
                <x-media.image
                    :media="$cause->featuredImage"
                    size="hero"
                    :eager="true"
                    class="w-full rounded-lg object-cover"
                />
            @endif

            @if ($cause->description)
                <div class="prose-scghf space-y-4 text-[var(--text-primary)]">{!! $cause->description !!}</div>
            @endif

            @if ($cause->project)
                <p class="text-sm text-[var(--text-secondary)]">
                    {{ __('This appeal supports') }}
                    <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('projects.show', $cause->project) }}">
                        {{ $cause->project->title }}</a>.
                </p>
            @endif

            @php $levels = $cause->givingLevels(); @endphp

            @if ($levels->isNotEmpty())
                <section aria-labelledby="levels-heading">
                    <h2 id="levels-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                        {{ __('What your gift buys') }}
                    </h2>

                    <ul role="list" class="mt-4 space-y-3">
                        @foreach ($levels as $level)
                            <li class="rounded-lg border border-[var(--border)] p-4">
                                <p class="font-semibold text-[var(--text-primary)]">
                                    {{ $level['amount']->format() }} — {{ $level['label'] }}
                                </p>

                                @if ($level['description'])
                                    <p class="mt-1 text-sm text-[var(--text-secondary)]">{{ $level['description'] }}</p>
                                @endif

                                @if ($cause->acceptsDonations())
                                    <a
                                        class="mt-2 inline-block text-sm font-semibold text-[var(--brand-primary)] hover:underline"
                                        href="{{ route('donate', ['cause' => $cause->slug, 'amount' => $level['amount']->toMajorString()]) }}"
                                    >{{ __('Give :amount', ['amount' => $level['amount']->format()]) }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($spending->isNotEmpty())
                <section aria-labelledby="spending-heading">
                    <h2 id="spending-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                        {{ __('Where the money went') }}
                    </h2>

                    <p class="mt-1 text-sm text-[var(--text-secondary)]">
                        {{ __('Payments that have actually left our account for this appeal.') }}
                    </p>

                    {{--
                        ⚠ Totals by category, never the individual payments. A
                        payout record carries a payee name and often the person
                        it was spent on — publishing the rows would publish who
                        received school fees or a medical payment. Categories
                        with too few payments to be safe are folded into
                        "Other", because a single medical payment beside a known
                        beneficiary is an identification.
                    --}}
                    <table class="mt-4 w-full max-w-md text-left text-sm">
                        <caption class="sr-only">{{ __('Spending on this appeal, by category') }}</caption>
                        <tbody>
                            @foreach ($spending as $row)
                                <tr class="border-b border-[var(--border)]">
                                    <td class="py-2 text-[var(--text-secondary)]">{{ $row['label'] }}</td>
                                    <td class="py-2 text-right font-medium text-[var(--text-primary)]">{{ $row['amount']->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            @if ($updates->isNotEmpty())
                <section aria-labelledby="updates-heading">
                    <h2 id="updates-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                        {{ __('Updates') }}
                    </h2>

                    <ul role="list" class="mt-4 space-y-6">
                        @foreach ($updates as $update)
                            <li>
                                <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                    {{ $update->published_at?->toFormattedDateString() }}
                                </p>
                                <h3 class="mt-1 font-semibold text-[var(--text-primary)]">{{ $update->title }}</h3>
                                <div class="prose-scghf mt-2 text-sm text-[var(--text-secondary)]">{!! $update->body !!}</div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside class="space-y-8">
            <div class="rounded-lg border border-[var(--border)] p-5">
                @if ($cause->is_urgent && $cause->acceptsDonations())
                    <p class="mb-3 text-sm font-semibold uppercase tracking-wide text-[var(--danger)]">
                        {{ __('Urgent') }}
                    </p>
                @endif

                <x-site.progress :cause="$cause" />

                @if ($cause->hasReachedItsGoal())
                    {{--
                        Said out loud, whatever the appeal does next.

                        Taking money silently against a goal that is already met
                        is the dishonest version of "keep accepting" — a donor
                        giving to a full appeal is entitled to know it is full
                        and to decide anyway.
                    --}}
                    <p class="mt-3 rounded-md bg-[var(--surface-sunken)] p-3 text-sm text-[var(--text-primary)]">
                        {{ __('We have reached the target for this appeal.') }}

                        @if ($cause->acceptsDonations())
                            {{ __('Gifts are still welcome and go to the same work.') }}
                        @elseif ($redirect = $cause->redirectTarget())
                            {{ __('You can support') }}
                            <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('causes.show', $redirect) }}">{{ $redirect->title }}</a>
                            {{ __('instead.') }}
                        @else
                            {{ __('Thank you to everybody who gave.') }}
                        @endif
                    </p>
                @endif

                @if ($cause->acceptsDonations())
                    <a
                        href="{{ route('donate', ['cause' => $cause->slug]) }}"
                        class="mt-5 block rounded-md bg-[var(--brand-secondary)] px-4 py-3 text-center font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                    >{{ __('Give to this appeal') }}</a>

                    {{-- Kept beside the card button rather than behind it.
                         Mobile Money and bank transfer need no gateway and cost
                         the foundation less per gift, and for a good share of
                         Ghanaian supporters they are the ONLY way they give. --}}
                    <p class="mt-2 text-center text-xs text-[var(--text-muted)]">
                        {{ __('or') }}
                        <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('give') }}">{{ __('pay by Mobile Money or bank transfer') }}</a>
                    </p>
                @endif

                @if ($cause->qualifiesForTaxRelief())
                    {{-- Only when the foundation actually holds a current GRA
                         approval. `qualifiesForTaxRelief()` is the single gate;
                         reading `is_tax_deductible` here would put an
                         unsupported claim in front of a donor. --}}
                    <p class="mt-4 text-xs text-[var(--text-muted)]">
                        {{ __('Gifts to this appeal may qualify for tax relief.') }}
                    </p>
                @endif
            </div>

            @if ($donors->isNotEmpty())
                <div>
                    <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Recent supporters') }}</h2>

                    <ul role="list" class="mt-2 space-y-1 text-sm text-[var(--text-secondary)]">
                        @foreach ($donors as $donor)
                            <li>{{ $donor }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php
                $shareUrl = route('causes.show', $cause);
                $shareText = __(':title — :raised raised so far', [
                    'title' => $cause->title,
                    'raised' => $cause->raisedAmount()->format(),
                ]);
            @endphp

            <div>
                <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Share this appeal') }}</h2>

                <ul role="list" class="mt-2 flex flex-wrap gap-4 text-sm">
                    <li>
                        <a
                            class="text-[var(--brand-primary)] hover:underline"
                            href="https://wa.me/?text={{ urlencode($shareText.' '.$shareUrl) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >{{ __('WhatsApp') }}</a>
                    </li>
                    <li>
                        <a
                            class="text-[var(--brand-primary)] hover:underline"
                            href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($shareUrl) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >{{ __('Facebook') }}</a>
                    </li>
                    <li>
                        <a
                            class="text-[var(--brand-primary)] hover:underline"
                            href="mailto:?subject={{ rawurlencode($cause->title) }}&body={{ rawurlencode($shareUrl) }}"
                        >{{ __('Email') }}</a>
                    </li>
                </ul>
            </div>
        </aside>
    </div>
</x-site.page-shell>
