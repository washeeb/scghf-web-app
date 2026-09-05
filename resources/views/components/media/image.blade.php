{{--
    An image from the library, rendered the way a phone on 3G needs it.

    ── It refuses to render an unpublishable file ──────────────────────────────

    `Media::isPublishable()` is the gate: alt text present, metadata removed.
    This component honours it rather than trusting the caller, because the
    caller is a page template and the consequence of a mistake there is a
    photograph carrying a child's home coordinates on a public page.

    Nothing is rendered when the gate refuses — no broken image, no placeholder
    with a filename in it. In the admin panel the file is listed with the reason
    it cannot be published, which is where that belongs.

    ── srcset, because the widths exist ────────────────────────────────────────

    Three conversions are generated for every image. Handing the browser one of
    them and hoping is the common mistake: a phone would download the 1600px
    hero to display it 380px wide, which on a metered Ghanaian connection is
    somebody's data spent on pixels they cannot see.

    ── width and height are not optional ───────────────────────────────────────

    Without them the browser does not know the aspect ratio until the bytes
    arrive, so the page reflows as each image lands. That is Cumulative Layout
    Shift, it is a Core Web Vitals metric this project has a budget for, and it
    is the thing that makes a donor tap the wrong button.

    @param media     an App\Models\Media
    @param size      thumb | card | hero — the largest this will render at
    @param eager     true for the ONE image above the fold; everything else lazy
    @param class     passed through to the <img>
--}}
@props([
    'media' => null,
    'size' => 'card',
    'eager' => false,
])

@php
    /** @var \App\Models\Media|null $media */
    $renderable = $media instanceof \App\Models\Media && $media->isPublishable();
@endphp

@if ($renderable)
    @php
        // Only the widths that exist. A conversion that has not been generated
        // yet — the first minute after an upload, while the cron queue catches
        // up — must not appear in srcset as a URL that 404s.
        $widths = collect(config('media.conversions', []))
            ->filter(fn (array $spec, string $name) => $media->hasGeneratedConversion($name))
            ->map(fn (array $spec, string $name) => $media->conversionUrl($name).' '.$spec['width'].'w');

        $requested = config('media.conversions.'.$size.'.width', 800);

        $intrinsicWidth = $media->width();
        $intrinsicHeight = $media->height();

        // The rendered box, capped at the original's real width so the browser
        // is never told to draw an image larger than the pixels it has.
        $displayWidth = $intrinsicWidth === null
            ? $requested
            : min($requested, $intrinsicWidth);

        $displayHeight = ($intrinsicWidth && $intrinsicHeight)
            ? (int) round($displayWidth * ($intrinsicHeight / $intrinsicWidth))
            : null;
    @endphp

    <img
        src="{{ $media->conversionUrl($size) }}"
        @if ($widths->isNotEmpty())
            srcset="{{ $widths->implode(', ') }}"
            {{-- Honest rather than clever: the layout caps content at max-w-6xl
                 (72rem), so above that breakpoint an image is never wider than
                 the box it sits in. A `100vw` here would make every phone
                 download the hero. --}}
            sizes="{{ $sizes ?? '(min-width: 1152px) 1100px, 100vw' }}"
        @endif
        alt="{{ $media->altText() }}"
        @if ($displayWidth) width="{{ $displayWidth }}" @endif
        @if ($displayHeight) height="{{ $displayHeight }}" @endif
        {{-- `eager` for the one image above the fold and lazy for the rest.
             Lazy-loading the hero delays the Largest Contentful Paint, which is
             the metric it looks like it should help. --}}
        loading="{{ $eager ? 'eager' : 'lazy' }}"
        decoding="{{ $eager ? 'sync' : 'async' }}"
        @if ($eager) fetchpriority="high" @endif
        {{ $attributes->class(['max-w-full h-auto']) }}
    >

    @if ($media->caption || $media->credit)
        {{-- A caption belongs to the image, so it is rendered by whatever draws
             the image. Credit is a legal obligation for donated photography and
             is the first thing dropped when captions are left to page authors. --}}
        <figcaption class="mt-2 text-sm text-[var(--text-muted)]">
            {{ $media->caption }}
            @if ($media->credit)
                <span class="italic">{{ __('Photo: :credit', ['credit' => $media->credit]) }}</span>
            @endif
        </figcaption>
    @endif
@endif
