{{--
    Click to load.

    ── The player is not downloaded until somebody asks for it ─────────────────

    An embedded YouTube iframe pulls roughly a megabyte of JavaScript and sets
    third-party cookies before anybody presses play. On a metered Ghanaian
    connection that is a real cost imposed on every visitor for a video most of
    them will not watch — and the cookies are a consent question this site would
    then have to answer.

    So the poster image is a link. Pressing it opens the video; not pressing it
    costs nothing and tracks nobody.
--}}
<x-blocks.section :section="$section" :heading="$section->field('heading')">
    <a
        href="{{ $section->field('video_url') }}"
        target="_blank"
        rel="noopener noreferrer"
        class="group relative block overflow-hidden rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
    >
        @if (($poster ?? null)?->isPublishable())
            <x-media.image :media="$poster" size="hero" class="w-full" />
        @else
            <span class="block aspect-video w-full bg-[var(--surface-sunken)]"></span>
        @endif

        <span class="absolute inset-0 flex items-center justify-center">
            <span class="rounded-full bg-black/60 p-5 text-white transition group-hover:bg-black/75">
                <svg class="size-8" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M8 5v14l11-7z" />
                </svg>
            </span>
        </span>

        <span class="sr-only">{{ __('Play the video (opens in a new tab)') }}</span>
    </a>

    @if ($caption = $section->field('caption'))
        <p class="mt-3 text-sm text-[var(--text-muted)]">{{ $caption }}</p>
    @endif
</x-blocks.section>
