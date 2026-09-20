{{--
    The hero.

    ── The mobile crop is a separate image, not the desktop one scaled ─────────

    A 1600px hero downscaled by the browser is the single largest cost of a
    first paint on a 3G connection, and it is what the LCP budget in CLAUDE.md
    is mostly spent on. `image_mobile` lets an editor supply a crop that is both
    smaller and better composed for a narrow screen; `<picture>` picks it before
    anything is downloaded.

    The overlay exists so white text stays legible over an arbitrary
    photograph — an editor cannot know what they will upload next.
--}}
@php
    $presentation = $section->presentation();
    $desktop = $image ?? null;
    $mobile = $imageMobile ?? null;
    $opacity = max(0, min(90, (int) $section->field('overlay_opacity', 55)));
@endphp

@if ($desktop?->isPublishable())
    {{-- The browser's preload scanner finds this in <head> before it has
         parsed as far as the <picture> below: on a 3G connection that is
         the difference between the hero starting to download with the CSS
         and starting after it. The media queries mirror the <picture>, so
         only the crop this screen will use is fetched. --}}
    @push('head')
        @if ($mobile?->isPublishable())
            <link rel="preload" as="image" href="{{ $mobile->conversionUrl('card') }}" media="(max-width: 767px)" fetchpriority="high">
            <link rel="preload" as="image" href="{{ $desktop->conversionUrl('hero') }}" media="(min-width: 768px)" fetchpriority="high">
        @else
            <link rel="preload" as="image" href="{{ $desktop->conversionUrl('hero') }}" fetchpriority="high">
        @endif
    @endpush
@endif

{{--
    ── The shape, from the chosen template ─────────────────────────────────────

    A full-bleed photograph, the headline in the serif display face with its
    first word underlined in the accent, two pill buttons, and — the detail
    that makes it read as the template — the next section's white panel
    rising into the bottom of the picture with rounded corners. That panel is
    the `aria-hidden` div at the foot; the extra bottom padding on the text
    box makes room for it.
--}}
@php
    $heading = (string) $section->field('heading');
    // The first word gets the underline. Split on whitespace only, so a
    // heading in one long word is simply underlined whole.
    $words = preg_split('/\s+/u', trim($heading), 2) ?: [$heading];
    $centred = $presentation->get('alignment') === 'centre';
    $hasImage = $desktop?->isPublishable();
@endphp

<section class="relative isolate overflow-hidden {{ $presentation->sectionClasses() }}">
    @if ($hasImage)
        <picture>
            @if ($mobile?->isPublishable())
                <source media="(max-width: 767px)" srcset="{{ $mobile->conversionUrl('card') }}">
            @endif

            <img
                src="{{ $desktop->conversionUrl('hero') }}"
                alt=""
                {{-- Decorative: the headline beside it carries the meaning, and
                     announcing the photograph as well would read it twice. --}}
                aria-hidden="true"
                class="absolute inset-0 -z-10 size-full object-cover"
                {{-- The one image above the fold. Lazy-loading it delays the
                     Largest Contentful Paint, which is the metric it looks like
                     it should help. --}}
                loading="eager"
                fetchpriority="high"
                decoding="sync"
            >
        </picture>

        {{-- Darker at the foot than the head, so the headline sits on the
             calmer part of any photograph and the panel below has an edge. --}}
        <div class="absolute inset-0 -z-10 bg-linear-to-b from-black/30 via-black to-black" style="opacity: {{ $opacity / 100 }}"></div>
    @endif

    <div class="{{ $presentation->containerClasses() }} {{ $hasImage ? 'pt-20 pb-28 text-white sm:pt-28 sm:pb-36 lg:pt-32 lg:pb-40' : '' }}">
        <div @class(['max-w-3xl', 'mx-auto text-center' => $centred])>
            @if ($eyebrow = $section->field('eyebrow'))
                <p @class(['eyebrow', 'eyebrow-on-dark' => $hasImage])>{{ $eyebrow }}</p>
            @endif

            <h1 class="mt-3 text-4xl leading-[1.08] tracking-tight sm:text-5xl lg:text-6xl">
                <span class="underline decoration-[var(--brand-secondary)] decoration-[0.12em] underline-offset-[0.14em]">{{ $words[0] }}</span>{{ isset($words[1]) ? ' '.$words[1] : '' }}
            </h1>

            @if ($subheading = $section->field('subheading'))
                <p class="mt-5 max-w-2xl text-lg leading-relaxed opacity-95 sm:text-xl {{ $centred ? 'mx-auto' : '' }}">{{ $subheading }}</p>
            @endif

            @php
                $ctas = collect([
                    ['label' => $section->field('primary_cta_label'), 'url' => $section->field('primary_cta_url'), 'primary' => true],
                    ['label' => $section->field('secondary_cta_label'), 'url' => $section->field('secondary_cta_url'), 'primary' => false],
                ])->filter(fn (array $cta) => filled($cta['label']) && filled($cta['url']));
            @endphp

            @if ($ctas->isNotEmpty())
                <div class="mt-8 flex flex-wrap gap-3 {{ $centred ? 'justify-center' : '' }}">
                    @foreach ($ctas as $cta)
                        <a
                            href="{{ $cta['url'] }}"
                            @class([
                                'btn',
                                'btn-accent' => $cta['primary'],
                                'btn-outline' => ! $cta['primary'],
                                'backdrop-blur-sm' => ! $cta['primary'] && $hasImage,
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

    @if ($hasImage)
        {{-- The panel the next section stands on, drawn in the page background
             so whichever palette is active it matches what follows. --}}
        <div aria-hidden="true" class="absolute inset-x-3 bottom-0 h-8 rounded-t-[var(--radius-2xl)] bg-[var(--bg)] sm:inset-x-6 sm:h-12"></div>
    @endif
</section>
