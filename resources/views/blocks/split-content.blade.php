{{--
    Prose beside an image, with the image on either side.

    The photograph is set slightly askew on a tinted card behind it — the
    template's stacked-print look — and straightens on hover. Under
    `prefers-reduced-motion` the transition is off (app.css), so it simply
    sits askew.
--}}
@php
    $imageFirst = $section->field('image_position', 'left') === 'left';
    $presentation = $section->presentation();
    $onDark = in_array($presentation->get('background'), ['brand', 'brand-secondary', 'inverse'], true) || $presentation->get('dark_variant');
@endphp

<x-blocks.section :section="$section">
    <div class="grid items-center gap-12 md:grid-cols-2 lg:gap-16">
        <div @class(['md:order-2' => ! $imageFirst])>
            @if (($image ?? null)?->isPublishable())
                <div class="group relative mx-auto max-w-md md:max-w-none">
                    <div aria-hidden="true" class="absolute inset-0 -rotate-3 rounded-[var(--radius-2xl)] bg-[color-mix(in_srgb,var(--brand-secondary)_22%,transparent)] transition duration-300 group-hover:-rotate-2"></div>
                    <x-media.image :media="$image" size="card" credit="title" class="relative aspect-[4/3] w-full rotate-2 rounded-[var(--radius-2xl)] object-cover shadow-[var(--shadow-lg)] transition duration-300 group-hover:rotate-0" />
                </div>
            @endif
        </div>

        <div @class(['md:order-1' => ! $imageFirst])>
            @if ($eyebrow = $section->field('eyebrow'))
                <p @class(['eyebrow', 'eyebrow-on-dark' => $onDark])>{{ $eyebrow }}</p>
            @endif

            <h2 class="mt-3 text-3xl tracking-tight sm:text-4xl">{{ $section->field('heading') }}</h2>

            @if ($body = $section->field('body'))
                <div class="prose mt-5 max-w-none text-[var(--text-primary)] prose-p:leading-relaxed">@clean($body)</div>
            @endif

            @if (filled($section->field('cta_label')) && filled($section->field('cta_url')))
                <a href="{{ $section->field('cta_url') }}" class="btn btn-accent mt-7">{{ $section->field('cta_label') }}</a>
            @endif
        </div>
    </div>
</x-blocks.section>
