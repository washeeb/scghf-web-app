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

<section class="relative isolate {{ $presentation->sectionClasses() }}">
    @if ($desktop?->isPublishable())
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

        <div class="absolute inset-0 -z-10 bg-black" style="opacity: {{ $opacity / 100 }}"></div>
    @endif

    <div class="{{ $presentation->containerClasses() }} {{ $desktop?->isPublishable() ? 'py-24 text-white sm:py-32' : '' }}">
        @if ($eyebrow = $section->field('eyebrow'))
            <p class="text-sm font-semibold uppercase tracking-wide opacity-90">{{ $eyebrow }}</p>
        @endif

        <h1 class="mt-2 text-3xl font-bold tracking-tight sm:text-5xl">{{ $section->field('heading') }}</h1>

        @if ($subheading = $section->field('subheading'))
            <p class="mt-4 max-w-2xl text-lg opacity-95 {{ $presentation->get('alignment') === 'centre' ? 'mx-auto' : '' }}">{{ $subheading }}</p>
        @endif

        @php
            $ctas = collect([
                ['label' => $section->field('primary_cta_label'), 'url' => $section->field('primary_cta_url'), 'primary' => true],
                ['label' => $section->field('secondary_cta_label'), 'url' => $section->field('secondary_cta_url'), 'primary' => false],
            ])->filter(fn (array $cta) => filled($cta['label']) && filled($cta['url']));
        @endphp

        @if ($ctas->isNotEmpty())
            <div class="mt-8 flex flex-wrap gap-3 {{ $presentation->get('alignment') === 'centre' ? 'justify-center' : '' }}">
                @foreach ($ctas as $cta)
                    <a
                        href="{{ $cta['url'] }}"
                        @class([
                            'rounded-md px-5 py-3 font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]',
                            'bg-[var(--brand-secondary)] text-[var(--text-on-secondary)]' => $cta['primary'],
                            'border border-current' => ! $cta['primary'],
                        ])
                    >{{ $cta['label'] }}</a>
                @endforeach
            </div>
        @endif
    </div>
</section>
