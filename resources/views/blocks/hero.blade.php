{{--
    The hero — one slide, or several.

    ── Slide one is the block's own fields ─────────────────────────────────────

    Not the first row of a repeater. That keeps every hero built before this
    existed rendering exactly as it did, and it keeps the headline and the
    picture that the preload hint, the crawler and the LCP measurement all
    care about in the place they were already in. Extra slides follow it.

    ── The mobile crop is a separate image, not the desktop one scaled ─────────

    A 1600px hero downscaled by the browser is the single largest cost of a
    first paint on a 3G connection, and it is what the LCP budget in CLAUDE.md
    is mostly spent on. `image_mobile` lets an editor supply a crop that is both
    smaller and better composed for a narrow screen; `<picture>` picks it before
    anything is downloaded.

    (The resolver supplies that crop as `image_mobile`; this file read it as
    `$imageMobile`, so it had never once rendered. It does now.)

    ── Only the first slide is eager ───────────────────────────────────────────

    The rest are `loading="lazy"` and `fetchpriority="low"`. A four-slide hero
    that downloaded four full-width photographs before the page settled would
    cost more than the whole rest of the homepage on the connections this site
    is built for — and three of them are behind a slide nobody may ever see.
--}}
@php
    use App\Blocks\Options\HeroHeights;

    $presentation = $section->presentation();
    $library = collect($slideMedia ?? []);
    $opacity = max(0, min(90, (int) $section->field('overlay_opacity', 55)));
    $height = (string) $section->field('height', HeroHeights::DEFAULT);
    $centred = $presentation->get('alignment') === 'centre';

    /*
     * One shape for every slide, whether it came from the block's own fields
     * or from a row — so the markup below never branches on where a slide
     * came from. A slide with no headline is not a slide.
     */
    $build = function (array $raw) use ($library): ?array {
        $heading = trim((string) ($raw['heading'] ?? ''));

        if ($heading === '') {
            return null;
        }

        $resolve = function (mixed $value) use ($library) {
            if ($value instanceof App\Models\Media) {
                return $value->isPublishable() ? $value : null;
            }

            $media = is_numeric($value) ? $library->get((int) $value) : null;

            return $media?->isPublishable() ? $media : null;
        };

        // The first word takes the underline. A heading of one long word is
        // simply underlined whole.
        $words = preg_split('/\s+/u', $heading, 2) ?: [$heading];

        return [
            'eyebrow' => (string) ($raw['eyebrow'] ?? ''),
            'first' => $words[0],
            'rest' => $words[1] ?? '',
            'heading' => $heading,
            'subheading' => (string) ($raw['subheading'] ?? ''),
            'desktop' => $resolve($raw['image'] ?? null),
            'mobile' => $resolve($raw['image_mobile'] ?? null),
            'ctas' => collect([
                ['label' => $raw['primary_cta_label'] ?? null, 'url' => $raw['primary_cta_url'] ?? null, 'primary' => true],
                ['label' => $raw['secondary_cta_label'] ?? null, 'url' => $raw['secondary_cta_url'] ?? null, 'primary' => false],
            ])->filter(fn (array $cta): bool => filled($cta['label']) && filled($cta['url']))->values(),
        ];
    };

    $slides = collect([$build([
        'eyebrow' => $section->field('eyebrow'),
        'heading' => $section->field('heading'),
        'subheading' => $section->field('subheading'),
        'image' => $image ?? null,
        'image_mobile' => $image_mobile ?? null,
        'primary_cta_label' => $section->field('primary_cta_label'),
        'primary_cta_url' => $section->field('primary_cta_url'),
        'secondary_cta_label' => $section->field('secondary_cta_label'),
        'secondary_cta_url' => $section->field('secondary_cta_url'),
    ])])
        ->concat(collect((array) $section->field('slides', []))->filter(fn (mixed $row): bool => is_array($row))->map($build))
        ->filter()
        ->values();

    $first = $slides->first();
    $isSlider = $slides->count() > 1;
    // Any slide with a picture makes the band full-bleed: the generic section
    // padding would otherwise draw the page background above and below the
    // photograph, which is the gap it used to hide by covering the padding.
    $anyImage = $slides->contains(fn (array $slide): bool => $slide['desktop'] !== null);
    $autoplay = max(0, min(30, (int) $section->field('autoplay_seconds', 7)));
    $hasImage = $first !== null && $first['desktop'] !== null;
@endphp

@if ($first !== null)
    @if ($hasImage)
        {{-- The browser's preload scanner finds this in <head> before it has
             parsed as far as the <picture> below: on a 3G connection that is
             the difference between the hero starting to download with the CSS
             and starting after it. The media queries mirror the <picture>, so
             only the crop this screen will use is fetched — and only for the
             slide that is on screen first. --}}
        @push('head')
            @if ($first['mobile'] !== null)
                <link rel="preload" as="image" href="{{ $first['mobile']->conversionUrl('card') }}" media="(max-width: 767px)" fetchpriority="high">
                <link rel="preload" as="image" href="{{ $first['desktop']->conversionUrl('hero') }}" media="(min-width: 768px)" fetchpriority="high">
            @else
                <link rel="preload" as="image" href="{{ $first['desktop']->conversionUrl('hero') }}" fetchpriority="high">
            @endif
        @endpush
    @endif

    {{--
        ── The shape, from the chosen template ─────────────────────────────────

        A full-bleed photograph, the headline in the serif display face with its
        first word underlined in the accent, two pill buttons, and — the detail
        that makes it read as the template — the next section's white panel
        rising into the bottom of the picture with rounded corners. That panel is
        the `aria-hidden` div at the foot; the extra bottom padding on the text
        box makes room for it.

        ── What a carousel owes somebody who is not using a mouse ──────────────

        `aria-roledescription="carousel"` on the region and `slide` on each
        panel; the panels that are off screen `inert`, so a keyboard cannot tab
        into a button it cannot see; controls that are real `<button>`s with
        names; a pause control, because WCAG 2.2 2.2.2 requires anything moving
        for more than five seconds to be stoppable; and no announcement at all
        until the visitor takes control — an auto-advance that interrupts a
        screen reader mid-sentence is worse than silence.
    --}}
    <section
        class="relative isolate overflow-hidden {{ $presentation->sectionClasses(withPadding: ! $anyImage) }} {{ $isSlider ? 'grid '.HeroHeights::minHeight($height) : '' }}"
        @if ($isSlider)
            data-hero-slider
            data-autoplay="{{ $autoplay }}"
            aria-roledescription="{{ __('carousel') }}"
            aria-label="{{ __('Highlights') }}"
        @endif
    >
        @foreach ($slides as $i => $slide)
            @php $active = $i === 0; @endphp

            <div
                @if ($isSlider)
                    data-hero-slide
                    role="group"
                    aria-roledescription="{{ __('slide') }}"
                    aria-label="{{ __(':position of :total', ['position' => $i + 1, 'total' => $slides->count()]) }}"
                    {{-- Every slide in the same grid cell: the band is as
                         tall as its tallest slide, with no absolute
                         positioning to clip a long sentence on a narrow
                         phone and no jump as it rotates. --}}
                    @class(['relative isolate col-start-1 row-start-1 flex flex-col justify-center transition-opacity duration-700 ease-out motion-reduce:transition-none', 'opacity-0 pointer-events-none' => ! $active])
                    @unless ($active) inert aria-hidden="true" @endunless
                @endif
            >
                @if ($slide['desktop'] !== null)
                    <picture>
                        @if ($slide['mobile'] !== null)
                            <source media="(max-width: 767px)" srcset="{{ $slide['mobile']->conversionUrl('card') }}">
                        @endif

                        <img
                            src="{{ $slide['desktop']->conversionUrl('hero') }}"
                            alt=""
                            {{-- Decorative: the headline beside it carries the meaning, and
                                 announcing the photograph as well would read it twice. --}}
                            aria-hidden="true"
                            class="absolute inset-0 -z-10 size-full object-cover"
                            @if ($active)
                                {{-- The one image above the fold. Lazy-loading it delays the
                                     Largest Contentful Paint, which is the metric it looks like
                                     it should help. --}}
                                loading="eager" fetchpriority="high" decoding="sync"
                            @else
                                loading="lazy" fetchpriority="low" decoding="async"
                            @endif
                        >
                    </picture>

                    {{-- Darker at the foot than the head, so the headline sits on the
                         calmer part of any photograph and the panel below has an edge. --}}
                    <div class="absolute inset-0 -z-10 bg-linear-to-b from-black/30 via-black to-black" style="opacity: {{ $opacity / 100 }}"></div>
                @endif

                <div class="{{ $presentation->containerClasses() }} {{ $anyImage ? HeroHeights::classes($height, $isSlider) : '' }} {{ $slide['desktop'] !== null ? 'text-white' : '' }} {{ $isSlider ? 'w-full' : '' }}">
                    <div @class(['max-w-3xl', 'mx-auto text-center' => $centred])>
                        @if ($slide['eyebrow'] !== '')
                            <p @class(['eyebrow', 'eyebrow-on-dark' => $slide['desktop'] !== null])>{{ $slide['eyebrow'] }}</p>
                        @endif

                        {{-- One h1 per page: the first slide carries it, the rest
                             are prose at the same size. Four h1s in a rotating
                             band is four page titles to anything reading the
                             outline. --}}
                        @if ($i === 0)
                            <h1 class="mt-3 text-4xl leading-[1.08] tracking-tight sm:text-5xl lg:text-6xl">
                                <span class="underline decoration-[var(--brand-secondary)] decoration-[0.12em] underline-offset-[0.14em]">{{ $slide['first'] }}</span>{{ $slide['rest'] !== '' ? ' '.$slide['rest'] : '' }}
                            </h1>
                        @else
                            <p class="font-display mt-3 text-4xl leading-[1.08] tracking-tight sm:text-5xl lg:text-6xl">
                                <span class="underline decoration-[var(--brand-secondary)] decoration-[0.12em] underline-offset-[0.14em]">{{ $slide['first'] }}</span>{{ $slide['rest'] !== '' ? ' '.$slide['rest'] : '' }}
                            </p>
                        @endif

                        @if ($slide['subheading'] !== '')
                            <p class="mt-5 max-w-2xl text-lg leading-relaxed opacity-95 sm:text-xl {{ $centred ? 'mx-auto' : '' }}">{{ $slide['subheading'] }}</p>
                        @endif

                        @if ($slide['ctas']->isNotEmpty())
                            <div class="mt-8 flex flex-wrap gap-3 {{ $centred ? 'justify-center' : '' }}">
                                @foreach ($slide['ctas'] as $cta)
                                    <a
                                        href="{{ $cta['url'] }}"
                                        @class([
                                            'btn',
                                            'btn-accent' => $cta['primary'],
                                            'btn-outline' => ! $cta['primary'],
                                            'backdrop-blur-sm' => ! $cta['primary'] && $slide['desktop'] !== null,
                                        ])
                                    >
                                        {{ $cta['label'] }}
                                        @unless ($cta['primary'])
                                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd" /></svg>
                                        @endunless
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach

        @if ($isSlider)
            {{-- The controls: an arrow either side, a dot per slide, and —
                 while it is advancing on its own — the active dot filling as
                 that slide's time runs out, so the movement is never a
                 surprise. --}}
            <div class="{{ $presentation->containerClasses() }} {{ HeroHeights::CONTROL_OFFSET }} pointer-events-none z-10 col-start-1 row-start-1 w-full self-end">
                <div @class(['pointer-events-auto flex items-center gap-2', 'justify-center' => $centred])>
                    <button type="button" data-hero-prev class="hero-control" aria-label="{{ __('Previous slide') }}">
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M17 10a.75.75 0 0 1-.75.75H5.612l4.158 3.96a.75.75 0 1 1-1.04 1.08l-5.5-5.25a.75.75 0 0 1 0-1.08l5.5-5.25a.75.75 0 1 1 1.04 1.08L5.612 9.25H16.25A.75.75 0 0 1 17 10Z" clip-rule="evenodd" /></svg>
                    </button>

                    <div class="mx-1 flex items-center gap-2">
                        @foreach ($slides as $i => $slide)
                            <button
                                type="button"
                                data-hero-dot="{{ $i }}"
                                aria-label="{{ Str::limit($slide['heading'], 60) }}"
                                aria-current="{{ $i === 0 ? 'true' : 'false' }}"
                                class="hero-dot"
                            >
                                <span data-hero-dot-fill class="hero-dot-fill"></span>
                            </button>
                        @endforeach
                    </div>

                    <button type="button" data-hero-next class="hero-control" aria-label="{{ __('Next slide') }}">
                        <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10Z" clip-rule="evenodd" /></svg>
                    </button>

                    @if ($autoplay > 0)
                        <button
                            type="button"
                            data-hero-pause
                            class="hero-control ms-1"
                            aria-label="{{ __('Pause the slideshow') }}"
                            data-label-pause="{{ __('Pause the slideshow') }}"
                            data-label-play="{{ __('Play the slideshow') }}"
                        >
                            <svg data-hero-pause-icon class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6 4.5A1.5 1.5 0 0 1 7.5 6v8a1.5 1.5 0 0 1-3 0V6A1.5 1.5 0 0 1 6 4.5Zm8 0A1.5 1.5 0 0 1 15.5 6v8a1.5 1.5 0 0 1-3 0V6A1.5 1.5 0 0 1 14 4.5Z" /></svg>
                            <svg data-hero-play-icon class="hidden size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.3 3.8a1 1 0 0 1 1.02.05l8 5.2a1 1 0 0 1 0 1.68l-8 5.2A1 1 0 0 1 5.8 15V5a1 1 0 0 1 .5-.87Z" /></svg>
                        </button>
                    @endif
                </div>
            </div>

            {{-- What changed, but only once the visitor is driving. --}}
            <p data-hero-status class="sr-only" role="status" aria-live="off"></p>
        @endif

        @if ($hasImage)
            {{-- The panel the next section stands on, drawn in the page background
                 so whichever palette is active it matches what follows. --}}
            <div aria-hidden="true" class="absolute inset-x-3 bottom-0 z-10 h-8 rounded-t-[var(--radius-2xl)] bg-[var(--bg)] sm:inset-x-6 sm:h-12"></div>
        @endif
    </section>
@endif
