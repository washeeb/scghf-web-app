{{--
    One news post.

    ── The body is trusted HTML, and that is a decision ────────────────────────

    It comes from the rich editor in the admin panel, written by staff who are
    already trusted with the whole CMS. It is NOT user-generated content, and
    the one place that could become user-generated — comments — is moderated and
    switched off. If that ever changes, this is the line that has to change with
    it.

    ── Sharing is a set of links, not a widget ─────────────────────────────────

    No third-party share buttons. Each one is a script from another company that
    loads on every article, sets cookies before anybody clicks, and costs a
    visitor on a metered connection real data for a button most will not press.
    A WhatsApp link is a URL — and WhatsApp is how this foundation's supporters
    actually share things.
--}}
<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="$post->title">
    <article class="max-w-3xl">
        <p class="mb-6 text-sm text-[var(--text-muted)]">
            @if ($post->published_at)
                <time datetime="{{ $post->published_at->toDateString() }}">
                    {{ $post->published_at->toFormattedDateString() }}
                </time>
            @endif

            @if ($post->author)
                · {{ __('by :name', ['name' => $post->author->name]) }}
            @endif

            @if ($post->reading_minutes)
                · {{ trans_choice('{1}1 minute read|[2,*]:count minute read', $post->reading_minutes, ['count' => $post->reading_minutes]) }}
            @endif
        </p>

        @if ($post->featuredImage)
            <x-media.image
                :media="$post->featuredImage"
                size="hero"
                {{-- The one eager image on the page. It is the largest thing
                     above the fold, which makes it the LCP element, and the
                     performance budget is spent here or nowhere. --}}
                :eager="true"
                class="mb-8 w-full rounded-lg object-cover"
            />
        @endif

        @if ($post->excerpt)
            <p class="mb-6 text-lg text-[var(--text-secondary)]">{{ $post->excerpt }}</p>
        @endif

        <div class="prose-scghf space-y-4 text-[var(--text-primary)]">
            {!! $post->body !!}
        </div>

        @if ($post->tags->isNotEmpty())
            <ul role="list" class="mt-8 flex flex-wrap gap-2 text-xs">
                @foreach ($post->tags as $tag)
                    <li class="rounded-full border border-[var(--border)] px-3 py-1 text-[var(--text-muted)]">
                        {{ $tag->name }}
                    </li>
                @endforeach
            </ul>
        @endif

        @php
            $shareUrl = route('news.show', $post);
            $shareText = $post->title;
        @endphp

        <div class="mt-10 border-t border-[var(--border)] pt-6">
            <h2 class="text-sm font-semibold text-[var(--text-primary)]">{{ __('Share this') }}</h2>

            <ul role="list" class="mt-3 flex flex-wrap gap-4 text-sm">
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
                        href="mailto:?subject={{ rawurlencode($shareText) }}&body={{ rawurlencode($shareUrl) }}"
                    >{{ __('Email') }}</a>
                </li>
            </ul>
        </div>
    </article>

    @if ($related->isNotEmpty())
        <section class="mt-16" aria-labelledby="related-heading">
            <h2 id="related-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                {{ __('More from us') }}
            </h2>

            <ul role="list" class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($related as $other)
                    <li>
                        <a
                            href="{{ route('news.show', $other) }}"
                            class="group block rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                        >
                            @if ($other->featuredImage)
                                <x-media.image
                                    :media="$other->featuredImage"
                                    size="card"
                                    class="mb-3 aspect-[3/2] w-full rounded-lg object-cover"
                                />
                            @endif

                            <h3 class="font-semibold text-[var(--text-primary)] group-hover:text-[var(--brand-primary)]">
                                {{ $other->title }}
                            </h3>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-site.page-shell>
