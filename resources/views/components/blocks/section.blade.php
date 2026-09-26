{{--
    The wrapper every block renders inside.

    ── One place decides how a section is framed ───────────────────────────────

    Background, padding, width, alignment and device visibility come from
    `SectionSettings`, which resolves each stored value through a fixed map. No
    block view builds those classes itself, so no block can be styled in a way
    the others cannot — and a change to the spacing scale moves every block on
    the site at once.

    ── The heading level is h2, always ─────────────────────────────────────────

    The page's own title is the h1. A block emitting a second h1 breaks the
    document outline a screen reader navigates by, and it is the single easiest
    accessibility mistake to make in a CMS with twenty block types.

    @param section  the App\Models\PageSection being rendered
    @param eyebrow  optional; the small uppercase line above the heading
    @param heading  optional; rendered as the h2 if given
    @param intro    optional lead paragraph under the heading
--}}
@props(['section', 'eyebrow' => null, 'heading' => null, 'intro' => null])

@php
    $presentation = $section->presentation();
    $centred = $presentation->get('alignment') === 'centre';
    // On a brand or inverse band the eyebrow takes the band's ink.
    $onDark = in_array($presentation->get('background'), ['brand', 'brand-secondary', 'inverse'], true) || $presentation->get('dark_variant');
@endphp

<section
    class="{{ $presentation->sectionClasses() }}"
    @if ($section->name) data-block="{{ $section->block_type }}" @endif
>
    <div class="{{ $presentation->containerClasses() }}">
        @if (filled($eyebrow))
            <p @class(['eyebrow', 'eyebrow-on-dark' => $onDark])>{{ $eyebrow }}</p>
        @endif

        @if (filled($heading))
            <h2 @class(['max-w-3xl text-3xl tracking-tight sm:text-4xl', 'mt-3' => filled($eyebrow), 'mx-auto' => $centred])>{{ $heading }}</h2>
        @endif

        @if (filled($intro))
            <p @class(['mt-4 max-w-2xl text-lg', 'text-[var(--text-muted)]' => ! $onDark, 'opacity-90' => $onDark, 'mx-auto' => $centred])>
                {{ $intro }}
            </p>
        @endif

        @if (filled($eyebrow) || filled($heading) || filled($intro))
            <div class="mt-10">{{ $slot }}</div>
        @else
            {{ $slot }}
        @endif
    </div>
</section>
