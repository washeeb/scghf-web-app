{{--
    Prose.

    ── The body is the one place stored HTML is printed unescaped ─────────────

    It comes from the rich-text editor, which only staff can reach, and it is
    sanitised on the way in. That combination is what makes `{!! !!}` acceptable
    here and nowhere else — a block that printed donor-supplied HTML this way
    would be stored XSS.

    `prose` caps the line length. Text running the full width of a laptop is
    measurably harder to read, and it is the commonest thing a CMS gets wrong.
--}}
<x-blocks.section :section="$section" :heading="$section->field('heading')">
    <div class="prose prose-lg max-w-none text-[var(--text-primary)] {{ $section->field('width') === 'prose' ? 'lg:max-w-3xl' : '' }}">
        {!! $section->field('body') !!}
    </div>
</x-blocks.section>
