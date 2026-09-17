{{--
    Prose.

    ── The body is the one place stored HTML is printed unescaped ─────────────

    It comes from the rich-text editor, which only staff can reach, and it is
    sanitised on the way OUT by `@clean` (App\Support\Html) — allowlisted
    tags, no scripts, no event handlers, no javascript: links. A comment here
    once said it was sanitised on the way in; nothing did that.

    `prose` caps the line length. Text running the full width of a laptop is
    measurably harder to read, and it is the commonest thing a CMS gets wrong.
--}}
<x-blocks.section :section="$section" :heading="$section->field('heading')">
    <div class="prose-scghf text-lg {{ $section->field('width') === 'prose' ? 'lg:max-w-3xl' : '' }}">
        @clean($section->field('body'))
    </div>
</x-blocks.section>
