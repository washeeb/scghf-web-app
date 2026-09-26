{{--
    A CMS page.

    ── The blocks are rendered by key, through the registry ────────────────────

    `@include` with a variable name would let a `block_type` in the database
    choose which file gets executed — a template injection with the database as
    its input. The registry is consulted first, so only a key it knows can reach
    a view, and `PageSection::isRenderable()` has already dropped anything whose
    type has gone.

    ── Every block gets its data as variables, never a query of its own ────────

    `BlockDataResolver` fetches what each block needs, with limits and eager
    loading, before rendering starts. A `@foreach (Cause::live()->get())` in a
    block view is untestable, invisible in an audit, and the usual route to an
    N+1 that only appears once the site has real content on it.

    ── Every page has one h1 and, off the homepage, a breadcrumb ───────────────

    A hero or page-header block supplies the h1 itself. A page that opens with
    anything else — a policy that is one rich-text block, a get-involved page
    that is prose and a form — gets the standard header the code-backed pages
    use: the trail, the title, the excerpt as a lead. Until Phase 6 Module 7
    such a page had no h1 at all, which is the easiest accessibility mistake to
    make when a page is assembled from parts, and the one a screen-reader user
    hits first.
--}}
@php
    $resolver = app(App\Blocks\BlockDataResolver::class);

    $suppliesOwnHeader = in_array($sections->first()?->block_type, ['hero', 'page-header'], true);

    $crumbs = [];

    if (! $page->is_homepage) {
        $crumbs[] = ['label' => __('Home'), 'url' => url('/')];

        foreach ($page->ancestors() as $ancestor) {
            $crumbs[] = ['label' => $ancestor->title, 'url' => $ancestor->isLive() ? url($ancestor->path) : null];
        }

        $crumbs[] = ['label' => $page->title, 'url' => null];
    }
@endphp

<x-layouts.app :meta="App\Support\PageMeta::for($page)">
    @if ($isPreview)
        {{-- Unmissable on purpose. Somebody looking at a preview of a draft
             needs to know the public cannot see this, or they will wonder why
             nobody has responded to a campaign that never went live. --}}
        <div role="status" class="bg-[var(--brand-secondary)] px-4 py-3 text-center text-sm font-semibold text-[var(--text-on-secondary)]">
            {{ __('Preview — this is how the page will look. :status', ['status' => $page->isLive()
                ? __('It is live.')
                : __('It is not published, so visitors cannot see it.')]) }}
        </div>
    @endif

    @if ($crumbs !== [] && ! $suppliesOwnHeader)
        <x-site.breadcrumbs :crumbs="$crumbs" />
    @endif

    @if ($sections->isNotEmpty() && ! $suppliesOwnHeader)
        <div class="mx-auto max-w-6xl px-4">
            <header class="max-w-3xl py-6">
                <h1 class="text-3xl font-bold tracking-tight text-[var(--text-primary)] sm:text-4xl">{{ $page->title }}</h1>

                @if ($page->excerpt)
                    <p class="mt-3 text-lg text-[var(--text-secondary)]">{{ $page->excerpt }}</p>
                @endif

                @if ($page->is_locked && $page->published_at)
                    {{-- A policy page says when it last changed; a donor reading
                         the refund policy is entitled to know it is current. --}}
                    <p class="mt-2 text-sm text-[var(--text-muted)]">
                        {{ __('Last updated :date', ['date' => $page->updated_at->format('j F Y')]) }}
                    </p>
                @endif
            </header>
        </div>
    @endif

    @forelse ($sections as $section)
        @php $view = 'blocks.'.$section->block_type; @endphp

        @if (view()->exists($view))
            @include($view, ['section' => $section] + $resolver->for($section))
        @endif
    @empty
        {{--
            An empty page still renders its title.

            A blank response would look like a server fault to a visitor and
            like a deleted page to whoever built it. The admin list flags this
            case with a badge, which is where it gets fixed.
        --}}
        <div class="mx-auto max-w-3xl px-4 py-16">
            <h1 class="text-3xl font-bold tracking-tight text-[var(--text-primary)]">{{ $page->title }}</h1>

            @if ($page->excerpt)
                <p class="mt-4 text-[var(--text-muted)]">{{ $page->excerpt }}</p>
            @endif
        </div>
    @endforelse
</x-layouts.app>
