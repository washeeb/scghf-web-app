{{--
    A full-width band with one thing to do.

    ── Two shapes ──────────────────────────────────────────────────────────────

    With `background = image` and a picture chosen, it is the template's
    photograph band: the picture edge to edge, darkened, the title centred in
    the display face and one pill button under it. Otherwise it is a plain
    band — whatever colour the section's presentation settings give it — with
    the words on the left and the button on the right.

    The `background` field had offered "image" since the block was defined
    and this view drew nothing for it; the picture chosen for the home page's
    band was silently dropped until the template restyle.
--}}
@php
    $presentation = $section->presentation();
    $photo = $section->field('background') === 'image' && ($image ?? null)?->isPublishable() ? $image : null;
    $onDark = $photo !== null
        || in_array($presentation->get('background'), ['brand', 'brand-secondary', 'inverse'], true)
        || $presentation->get('dark_variant');
    $cta = filled($section->field('cta_label')) && filled($section->field('cta_url'));
@endphp

@if ($photo)
    <section class="relative isolate overflow-hidden {{ $presentation->sectionClasses() }}" @if ($section->name) data-block="{{ $section->block_type }}" @endif>
        <img
            src="{{ $photo->conversionUrl('hero') }}"
            alt=""
            aria-hidden="true"
            class="absolute inset-0 -z-10 size-full object-cover"
            loading="lazy"
            decoding="async"
        >
        <div class="absolute inset-0 -z-10 bg-black/60" aria-hidden="true"></div>

        <div class="{{ $presentation->containerClasses() }} py-16 text-white sm:py-24">
          {{-- Its own element: the container carries the section's alignment
               setting, and this band is centred whatever that says. --}}
          <div class="text-center">
            @if ($eyebrow = $section->field('eyebrow'))
                <p class="eyebrow eyebrow-on-dark justify-center">{{ $eyebrow }}</p>
            @endif

            <h2 class="mx-auto mt-3 max-w-3xl text-4xl tracking-tight sm:text-5xl">{{ $section->field('heading') }}</h2>

            @if ($body = $section->field('body'))
                <p class="mx-auto mt-4 max-w-2xl text-lg opacity-95">{{ $body }}</p>
            @endif

            @if ($cta)
                <a href="{{ $section->field('cta_url') }}" class="btn btn-accent mt-8">{{ $section->field('cta_label') }}</a>
            @endif
          </div>
        </div>
    </section>
@else
    <x-blocks.section :section="$section">
        <div class="flex flex-col items-start gap-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                @if ($eyebrow = $section->field('eyebrow'))
                    <p @class(['eyebrow mb-3', 'eyebrow-on-dark' => $onDark])>{{ $eyebrow }}</p>
                @endif

                <h2 class="text-3xl tracking-tight sm:text-4xl">{{ $section->field('heading') }}</h2>

                @if ($body = $section->field('body'))
                    <p class="mt-2 max-w-2xl opacity-90">{{ $body }}</p>
                @endif
            </div>

            @if ($cta)
                <a href="{{ $section->field('cta_url') }}" class="btn btn-accent shrink-0">{{ $section->field('cta_label') }}</a>
            @endif
        </div>
    </x-blocks.section>
@endif
