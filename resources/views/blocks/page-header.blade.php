{{-- A compact title band for an interior page. --}}
@php $presentation = $section->presentation(); @endphp

<section class="relative isolate {{ $presentation->sectionClasses() }}">
    @if (($image ?? null)?->isPublishable())
        <img
            src="{{ $image->conversionUrl('hero') }}"
            alt=""
            aria-hidden="true"
            class="absolute inset-0 -z-10 size-full object-cover opacity-25"
            loading="eager"
            decoding="sync"
        >
    @endif

    <div class="{{ $presentation->containerClasses() }}">
        <h1 class="max-w-3xl text-4xl tracking-tight sm:text-5xl">{{ $section->field('heading') }}</h1>

        @if ($subheading = $section->field('subheading'))
            <p class="mt-4 max-w-2xl text-lg text-[var(--text-muted)]">{{ $subheading }}</p>
        @endif
    </div>
</section>
