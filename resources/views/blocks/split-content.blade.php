{{-- Prose beside an image, with the image on either side. --}}
@php $imageFirst = $section->field('image_position', 'left') === 'left'; @endphp

<x-blocks.section :section="$section">
    <div class="grid items-center gap-10 md:grid-cols-2">
        <div @class(['md:order-2' => ! $imageFirst])>
            @if (($image ?? null)?->isPublishable())
                <x-media.image :media="$image" size="card" class="rounded-lg" />
            @endif
        </div>

        <div @class(['md:order-1' => ! $imageFirst])>
            @if ($eyebrow = $section->field('eyebrow'))
                <p class="text-sm font-semibold uppercase tracking-wide text-[var(--brand-primary)]">{{ $eyebrow }}</p>
            @endif

            <h2 class="mt-2 text-2xl font-semibold tracking-tight sm:text-3xl">{{ $section->field('heading') }}</h2>

            @if ($body = $section->field('body'))
                <div class="prose mt-4 max-w-none text-[var(--text-primary)]">{!! $body !!}</div>
            @endif

            @if (filled($section->field('cta_label')) && filled($section->field('cta_url')))
                <a
                    href="{{ $section->field('cta_url') }}"
                    class="mt-6 inline-block rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                >{{ $section->field('cta_label') }}</a>
            @endif
        </div>
    </div>
</x-blocks.section>
