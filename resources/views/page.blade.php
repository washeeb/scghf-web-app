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
--}}
@php
    $resolver = app(App\Blocks\BlockDataResolver::class);
@endphp

<x-layouts.app
    :title="$page->seoTitle()"
    :description="$page->seoDescription()"
    :noindex="! $page->seoShouldIndex()"
>
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
            <h1 class="text-3xl font-bold tracking-tight text-[var(--text)]">{{ $page->title }}</h1>

            @if ($page->excerpt)
                <p class="mt-4 text-[var(--text-muted)]">{{ $page->excerpt }}</p>
            @endif
        </div>
    @endforelse
</x-layouts.app>
