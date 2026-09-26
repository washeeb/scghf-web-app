{{--
    Frequently asked questions.

    ── `<details>`, like every other disclosure on this site ───────────────────

    Open and close with no JavaScript, readable by a search engine, and
    keyboard-operable because the browser already made it so. An accordion built
    from divs and click handlers is one that shows nothing at all while the
    script is still downloading — on a 3G connection, that is the whole page.

    ── The heading inside the summary, not around it ───────────────────────────

    `<summary>` is the button. Wrapping it in an `<h3>` puts a heading in the
    outline that a screen-reader user can jump between, while the summary keeps
    its own button semantics. The other order — a heading inside the details but
    outside the summary — produces a heading nobody can reach.

    ── The anchor id is what search results link to ────────────────────────────

    `SearchController` points at `#faq-{id}`, so a result lands on the question
    rather than at the top of a page of forty. `target:open` in the stylesheet
    is what makes a linked question expand on arrival.
--}}
@push('head')
    <script type="application/ld+json">{!! app(App\Support\StructuredData::class)->faqPage($categories->flatMap(fn ($category) => $category->faqs)->concat($uncategorised)) !!}</script>
@endpush

<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Frequently asked questions')"
    :lead="__('If your question is not here, please get in touch — we would rather answer it than have you wonder.')"
>
    @php
        $groups = $categories
            ->map(fn ($category) => ['heading' => $category->name, 'faqs' => $category->faqs])
            ->when($uncategorised->isNotEmpty(), fn ($groups) => $groups->push([
                'heading' => $categories->isEmpty() ? null : __('Other questions'),
                'faqs' => $uncategorised,
            ]));
    @endphp

    @forelse ($groups as $group)
        <section class="mb-12 max-w-3xl" @if ($group['heading']) aria-labelledby="faq-group-{{ $loop->index }}" @endif>
            @if ($group['heading'])
                <h2 id="faq-group-{{ $loop->index }}" class="mb-4 text-xl font-semibold text-[var(--text-primary)]">
                    {{ $group['heading'] }}
                </h2>
            @endif

            <div class="divide-y divide-[var(--border)] border-y border-[var(--border)]">
                @foreach ($group['faqs'] as $faq)
                    @php
                        // A question under a group heading is an h3; in a group
                        // with no heading (the uncategorised ones) it sits directly
                        // under the h1, so it is an h2 — a heading order that skips a
                        // level is a screen-reader user losing their place.
                        $questionLevel = $group['heading'] ? 'h3' : 'h2';
                    @endphp
                    <details id="faq-{{ $faq->getKey() }}" class="group py-4">
                        <{{ $questionLevel }}>
                            <summary class="cursor-pointer list-none font-medium text-[var(--text-primary)] marker:content-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">
                                <span class="flex items-start justify-between gap-4">
                                    <span>{{ $faq->question }}</span>

                                    {{-- Decoration. `aria-hidden` because the
                                         browser already announces the open and
                                         closed state of a details element. --}}
                                    <span aria-hidden="true" class="mt-1 shrink-0 text-[var(--text-muted)] transition group-open:rotate-45">+</span>
                                </span>
                            </summary>
                        </{{ $questionLevel }}>

                        <div class="prose-scghf mt-3 space-y-3 text-[var(--text-secondary)]">
                            @clean($faq->answer)
                        </div>
                    </details>
                @endforeach
            </div>
        </section>
    @empty
        <p class="text-[var(--text-secondary)]">
            {{ __('There are no questions here yet.') }}
        </p>
    @endforelse

    <p class="max-w-3xl text-[var(--text-secondary)]">
        {{ __('Still stuck?') }}
        <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('contact') }}">
            {{ __('Send us a message.') }}
        </a>
    </p>
</x-site.page-shell>
