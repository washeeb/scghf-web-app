{{--
    The frame every non-CMS public page sits in.

    Breadcrumbs, an h1, an optional lead paragraph, then the content. It exists
    so that eleven pages cannot each invent their own spacing, their own heading
    size and their own idea of where the breadcrumb goes — which is how a site
    ends up looking like it was built by eleven people.

    ── One h1, and it is the page title ────────────────────────────────────────

    Blocks inside a CMS page emit h2s for exactly this reason. A page with two
    h1s, or none, breaks the outline a screen-reader user navigates by, and it
    is the easiest accessibility mistake to make when every page is assembled
    from parts.

    @param meta   an App\Support\PageMeta
    @param crumbs breadcrumb trail
    @param title  the h1; defaults to the meta title without the site suffix
    @param lead   optional sentence under the heading
--}}
@props([
    'meta',
    'crumbs' => [],
    'title' => null,
    'lead' => null,
])

<x-layouts.app :meta="$meta">
    <x-site.breadcrumbs :crumbs="$crumbs" />

    <div class="mx-auto max-w-6xl px-4 pb-16">
        <header class="max-w-3xl py-6">
            <h1 class="text-3xl font-bold tracking-tight text-[var(--text-primary)] sm:text-4xl">
                {{ $title ?? str_replace(setting('seo.title_suffix', ''), '', $meta->title) }}
            </h1>

            @if ($lead)
                <p class="mt-3 text-lg text-[var(--text-secondary)]">{{ $lead }}</p>
            @endif
        </header>

        {{ $slot }}
    </div>
</x-layouts.app>
