{{--
    The news index.

    ── Cards are links, not divs with a link inside ────────────────────────────

    The whole card is one `<a>` wrapping the image and the heading, so the tap
    target is the card. A card whose only link is the four-word headline is a
    card nobody can hit on a phone, and it is the commonest way a listing page
    fails on the devices most of this foundation's visitors use.

    ── The category filter is a list of links ──────────────────────────────────

    Not a select with JavaScript. Each is a real URL, so it can be shared,
    bookmarked, and crawled — and it works before any script has loaded.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="$category?->name ?? __('News')"
    :lead="$category?->description"
>
    @if ($categories->isNotEmpty())
        <nav aria-label="{{ __('Categories') }}" class="mb-8">
            <ul class="flex flex-wrap gap-2 text-sm">
                <li>
                    <a
                        href="{{ route('news.index') }}"
                        @class([
                            'inline-block rounded-full border px-3 py-1',
                            'border-[var(--brand-primary)] bg-[var(--brand-primary)] text-[var(--text-on-brand)]' => $category === null,
                            'border-[var(--border)] text-[var(--text-secondary)] hover:border-[var(--brand-primary)]' => $category !== null,
                        ])
                        @if ($category === null) aria-current="page" @endif
                    >{{ __('All') }}</a>
                </li>

                @foreach ($categories as $option)
                    <li>
                        <a
                            href="{{ route('news.category', $option) }}"
                            @class([
                                'inline-block rounded-full border px-3 py-1',
                                'border-[var(--brand-primary)] bg-[var(--brand-primary)] text-[var(--text-on-brand)]' => $category?->is($option),
                                'border-[var(--border)] text-[var(--text-secondary)] hover:border-[var(--brand-primary)]' => ! $category?->is($option),
                            ])
                            @if ($category?->is($option)) aria-current="page" @endif
                        >{{ $option->name }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    @endif

    @forelse ($posts as $index => $post)
        @if ($loop->first)
            <ul role="list" class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        @endif

        <li>
            <a
                href="{{ route('news.show', $post) }}"
                class="group block h-full rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >
                @if ($post->featuredImage)
                    <x-media.image
                        :media="$post->featuredImage"
                        size="card"
                        {{-- The first three are above the fold on a laptop. The
                             rest are lazy, which is most of what keeps this
                             page cheap on a metered connection. --}}
                        :eager="$index < 3"
                        class="mb-3 aspect-[3/2] w-full rounded-lg object-cover"
                    />
                @endif

                <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">
                    {{ $post->category?->name ?? __('News') }}
                    @if ($post->published_at)
                        · <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->toFormattedDateString() }}</time>
                    @endif
                </p>

                <h2 class="mt-1 text-lg font-semibold text-[var(--text-primary)] group-hover:text-[var(--brand-primary)]">
                    {{ $post->title }}
                </h2>

                @if ($post->excerpt)
                    <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ $post->excerpt }}</p>
                @endif

                @if ($post->reading_minutes)
                    <p class="mt-2 text-xs text-[var(--text-muted)]">
                        {{ trans_choice('{1}1 minute read|[2,*]:count minute read', $post->reading_minutes, ['count' => $post->reading_minutes]) }}
                    </p>
                @endif
            </a>
        </li>

        @if ($loop->last)
            </ul>
        @endif
    @empty
        {{-- An empty state that says what will appear, rather than "no
             results" — this page is empty on a new site, not broken. --}}
        <p class="text-[var(--text-secondary)]">
            {{ __('There is nothing here yet. Updates from our work will appear on this page.') }}
        </p>
    @endforelse

    @if ($posts->hasPages())
        <div class="mt-10">{{ $posts->onEachSide(1)->links() }}</div>
    @endif
</x-site.page-shell>
