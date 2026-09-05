{{--
    Questions and answers.

    `<details>`, like every other disclosure on this site: announced state,
    keyboard operable, and readable with no JavaScript at all. A script-driven
    accordion hides every answer from a visitor whose bundle did not arrive —
    and from a search engine reading the page.
--}}
@if ($faqs->isNotEmpty())
    <x-blocks.section :section="$section" :heading="$section->field('heading')" :intro="$section->field('intro')">
        <div class="grid gap-8 md:grid-cols-2">
            <ul class="divide-y divide-[var(--border)]">
                @foreach ($faqs as $faq)
                    <li>
                        <details class="group py-4">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-medium text-[var(--text)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]">
                                {{ $faq->question }}

                                <svg class="size-5 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </summary>

                            <div class="prose prose-sm mt-3 max-w-none text-[var(--text-muted)]">{!! $faq->answer !!}</div>
                        </details>
                    </li>
                @endforeach
            </ul>

            @if (($image ?? null)?->isPublishable())
                <div class="hidden md:block">
                    <x-media.image :media="$image" size="card" class="rounded-lg" />
                </div>
            @endif
        </div>
    </x-blocks.section>
@endif
