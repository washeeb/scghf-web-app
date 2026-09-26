{{--
    Where you are.

    ── It is a nav with a name, and the current page is not a link ─────────────

    `aria-label` because a page can have several navigations and a screen-reader
    user picking from a list of them needs to tell them apart. `aria-current`
    on the last item because it is where you already are — rendering it as a
    link that goes nowhere is the commonest breadcrumb mistake, and it costs a
    keyboard user a tab stop on every page.

    ── The separator is decoration, and says so ───────────────────────────────

    `aria-hidden` on the chevron. Without it a screen reader reads "Home,
    greater-than, About, greater-than, Leadership", which is noise on every
    inner page of the site.

    ── The structured data is the point on a foundation's site ────────────────

    Most people meet an inner page through a search result, not by walking the
    navigation. `BreadcrumbList` is what makes that result show the trail rather
    than a raw URL.

    @param crumbs  [['label' => 'About', 'url' => '/about'], ['label' => 'Leadership', 'url' => null]]
--}}
@props(['crumbs' => []])

@if (count($crumbs) > 1)
    <nav aria-label="{{ __('Breadcrumb') }}" class="mx-auto max-w-6xl px-4 py-3">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-[var(--text-muted)]">
            @foreach ($crumbs as $index => $crumb)
                <li class="flex items-center gap-2">
                    @if ($index > 0)
                        <span aria-hidden="true">/</span>
                    @endif

                    @if ($crumb['url'] ?? null)
                        <a href="{{ $crumb['url'] }}" class="hover:text-[var(--brand-primary)] hover:underline">
                            {{ $crumb['label'] }}
                        </a>
                    @else
                        <span aria-current="page" class="font-medium text-[var(--text-primary)]">
                            {{ $crumb['label'] }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    {{-- In the body, not pushed to the head. A component rendered inside the
         layout's slot runs AFTER the head has already been sent, so a
         `@push` here would land in a stack nobody flushes again. JSON-LD is
         valid anywhere in the document, and this is where the data is. --}}
    <script type="application/ld+json">{!! app(App\Support\StructuredData::class)->breadcrumbs($crumbs) !!}</script>
@endif
