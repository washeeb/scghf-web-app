{{--
    The admin manual, rendered from resources/manual.

    A chapter list down the side on wide screens, the chapter itself in
    prose. The Markdown is the foundation's own and passes through the same
    sanitiser as CMS content, so nothing here is an injection surface.

    Styled with its own small stylesheet rather than utility classes: the
    panel ships Filament's compiled CSS, which has only the classes Filament
    uses — `prose` and an arbitrary grid template are not among them. The
    accent is a literal green rather than a token: the site's theme tokens
    are not loaded in the panel, and ThemeTokenCoverageTest holds every
    view to the tokens the theme defines.
--}}
<x-filament-panels::page>
    <style nonce="{{ $cspNonce ?? '' }}">
        .scghf-manual { display: grid; gap: 2rem; }
        @media (min-width: 1024px) { .scghf-manual { grid-template-columns: 16rem minmax(0, 1fr); } .scghf-manual-nav { position: sticky; top: 1.5rem; align-self: start; } }
        .scghf-manual-nav ul { list-style: none; margin: 0; padding: 0; font-size: 0.875rem; }
        .scghf-manual-nav a { display: block; border-radius: 0.375rem; padding: 0.5rem 0.75rem; color: inherit; text-decoration: none; }
        .scghf-manual-nav a:hover { background: rgba(0,0,0,0.05); }
        .dark .scghf-manual-nav a:hover { background: rgba(255,255,255,0.06); }
        .scghf-manual-nav a.is-current { font-weight: 600; color: #047857; }
        .dark .scghf-manual-nav a.is-current { color: #34d399; }
        .scghf-manual-article { border-radius: 0.75rem; background: #fff; padding: 1.5rem 2rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); border: 1px solid rgba(0,0,0,0.06); }
        .dark .scghf-manual-article { background: rgb(24 24 27); border-color: rgba(255,255,255,0.1); }
        .scghf-prose { max-width: 72ch; line-height: 1.65; font-size: 0.95rem; }
        .scghf-prose h1 { font-size: 1.75rem; font-weight: 700; margin: 0 0 1rem; }
        .scghf-prose h2 { font-size: 1.25rem; font-weight: 700; margin: 2rem 0 0.75rem; }
        .scghf-prose h3 { font-size: 1.05rem; font-weight: 600; margin: 1.5rem 0 0.5rem; }
        .scghf-prose p, .scghf-prose ul, .scghf-prose ol { margin: 0 0 1rem; }
        .scghf-prose ul { padding-left: 1.25rem; list-style: disc; }
        .scghf-prose ol { padding-left: 1.25rem; list-style: decimal; }
        .scghf-prose li { margin: 0.25rem 0; }
        .scghf-prose a { color: #047857; text-decoration: underline; }
        .dark .scghf-prose a { color: #34d399; }
        .scghf-prose code { font-family: ui-monospace, monospace; font-size: 0.85em; background: rgba(0,0,0,0.06); padding: 0.1em 0.35em; border-radius: 0.25rem; }
        .dark .scghf-prose code { background: rgba(255,255,255,0.1); }
        .scghf-prose img { max-width: 100%; height: auto; border-radius: 0.5rem; border: 1px solid rgba(0,0,0,0.1); margin: 0.5rem 0 1.25rem; }
        .scghf-prose table { width: 100%; border-collapse: collapse; font-size: 0.875rem; margin: 0 0 1.25rem; }
        .scghf-prose th, .scghf-prose td { border: 1px solid rgba(0,0,0,0.1); padding: 0.5rem 0.65rem; vertical-align: top; text-align: left; }
        .dark .scghf-prose th, .dark .scghf-prose td { border-color: rgba(255,255,255,0.12); }
        .scghf-prose th { background: rgba(0,0,0,0.03); font-weight: 600; }
        .dark .scghf-prose th { background: rgba(255,255,255,0.04); }
        .scghf-prose blockquote { border-left: 3px solid #10b981; padding-left: 1rem; color: inherit; opacity: 0.85; margin: 0 0 1rem; }
    </style>

    <div class="scghf-manual">
        <nav aria-label="{{ __('Chapters') }}" class="scghf-manual-nav">
            <ul>
                <li><a href="{{ static::getUrl() }}" @class(['is-current' => $this->chapter === null])>{{ __('Contents') }}</a></li>
                @foreach ($this->chapters() as $entry)
                    <li><a href="{{ $entry['url'] }}" @class(['is-current' => $this->chapter === $entry['slug']])>{{ $entry['title'] }}</a></li>
                @endforeach
            </ul>
        </nav>

        <article class="scghf-manual-article">
            <div class="scghf-prose">
                {!! $this->html() !!}
            </div>
        </article>
    </div>
</x-filament-panels::page>
