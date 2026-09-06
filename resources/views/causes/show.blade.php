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
                <x-site.progress :cause="$cause" />

                @if ($cause->acceptsDonations())
                    <a
                        href="{{ route('give') }}"
                        class="mt-5 block rounded-md bg-[var(--brand-secondary)] px-4 py-3 text-center font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                    >{{ __('Give to this appeal') }}</a>

                    {{-- Bank transfer and Mobile Money work today and need no
                         gateway. The card form arrives with the donation
                         module; this button is not a placeholder for it. --}}
                    <p class="mt-2 text-center text-xs text-[var(--text-muted)]">
                        {{ __('Bank transfer and Mobile Money') }}
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
