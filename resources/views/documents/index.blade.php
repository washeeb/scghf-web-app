{{--
    Reports, policies and financial statements.

    ── This page is why a donor trusts the payment form ────────────────────────

    A Ghanaian non-profit asking the public for money is expected to publish its
    accounts, its annual report and its safeguarding policy. Their absence is
    what a scam site has in common with a real one that never got round to it.

    ── The anchor ids are what search results link to ──────────────────────────

    `SearchController` points at `#document-{id}`, so a result lands on the
    document rather than the top of the page.
--}}
@php
    $labels = App\Filament\Resources\Documents\Schemas\DocumentForm::documentTypes();
@endphp

<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Reports & policies')"
    :lead="__('What we spend, what we achieved, and the rules we hold ourselves to.')"
>
    @forelse ($documents as $type => $group)
        <section class="mb-10 max-w-3xl" aria-labelledby="documents-{{ $loop->index }}">
            <h2 id="documents-{{ $loop->index }}" class="mb-3 text-xl font-semibold text-[var(--text-primary)]">
                {{ $labels[$type] ?? ucfirst(str_replace('_', ' ', (string) $type)) }}
            </h2>

            <ul role="list" class="divide-y divide-[var(--border)] border-y border-[var(--border)]">
                @foreach ($group as $document)
                    <li id="document-{{ $document->getKey() }}" class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 py-3">
                        <div>
                            <a
                                href="{{ route('documents.download', $document) }}"
                                class="font-medium text-[var(--brand-primary)] hover:underline"
                            >{{ $document->title }}</a>

                            @if ($document->description)
                                <p class="mt-0.5 text-sm text-[var(--text-secondary)]">{{ $document->description }}</p>
                            @endif
                        </div>

                        <p class="text-sm text-[var(--text-muted)]">
                            {{ collect([
                                $document->year,
                                $document->media?->human_readable_size,
                            ])->filter()->implode(' · ') }}
                        </p>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="text-[var(--text-secondary)]">
            {{ __('Our reports will be published on this page.') }}
        </p>
    @endforelse
</x-site.page-shell>
