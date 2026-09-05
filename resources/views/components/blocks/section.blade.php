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
    @param heading  optional; rendered as the h2 if given
    @param intro    optional lead paragraph under the heading
--}}
@props(['section', 'heading' => null, 'intro' => null])

@php $presentation = $section->presentation(); @endphp

<section
    class="{{ $presentation->sectionClasses() }}"
    @if ($section->name) data-block="{{ $section->block_type }}" @endif
>
    <div class="{{ $presentation->containerClasses() }}">
        @if (filled($heading))
            <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $heading }}</h2>
        @endif

        @if (filled($intro))
            <p class="mt-3 max-w-2xl text-[var(--text-muted)] {{ $presentation->get('alignment') === 'centre' ? 'mx-auto' : '' }}">
                {{ $intro }}
            </p>
        @endif

        @if (filled($heading) || filled($intro))
            <div class="mt-8">{{ $slot }}</div>
        @else
            {{ $slot }}
        @endif
    </div>
</section>
