{{--
    The head tags that decide how this page appears everywhere except on itself.

    ── Open Graph is how this foundation is actually shared ────────────────────

    Most of its supporters share links on WhatsApp. Without these tags a shared
    donation appeal renders as a bare blue URL — no title, no summary, no
    picture — and a bare link is a link nobody taps. `HasSeo::seoOpenGraph()`
    has existed since Phase 3 and was read by nothing until now.

    ── The canonical is not decoration ─────────────────────────────────────────

    The same page is reachable with a trailing slash, with a `?utm_source` from
    the newsletter, and through a redirect. Without a canonical a search engine
    treats those as competing pages, and the foundation's own campaign link
    outranks the page it points at.

    ── Twitter's tags are still needed ─────────────────────────────────────────

    Several apps read `twitter:card` and fall back to Open Graph only if it is
    absent — and the difference between `summary` and `summary_large_image` is
    the difference between a thumbnail and a picture somebody looks at.

    @param meta  an App\Support\PageMeta
--}}
@props(['meta'])

<title>{{ $meta->title }}</title>

@if ($meta->description)
    <meta name="description" content="{{ $meta->description }}">
@endif

@if ($meta->canonical)
    <link rel="canonical" href="{{ $meta->canonical }}">
@endif

@unless ($meta->shouldIndex())
    {{-- `noindex, nofollow`, and the header as well as the tag. `.env.example`
         has promised the X-Robots-Tag since Phase 2; a crawler that fetches a
         PDF or an image never parses HTML, so the tag alone does not cover
         everything this site serves. --}}
    <meta name="robots" content="noindex, nofollow">
@endunless

<meta property="og:type" content="{{ $meta->type }}">
<meta property="og:title" content="{{ $meta->title }}">
<meta property="og:url" content="{{ $meta->canonical ?? url()->current() }}">
<meta property="og:site_name" content="{{ setting('general.short_name', config('app.name')) }}">
<meta property="og:locale" content="{{ str_replace('-', '_', str_replace('_', '-', app()->getLocale())) }}">

@if ($meta->description)
    <meta property="og:description" content="{{ $meta->description }}">
@endif

@if ($meta->imageUrl)
    <meta property="og:image" content="{{ $meta->imageUrl }}">
    {{-- Alt text on a share image, because a screen-reader user meets this
         image in somebody else's timeline, where nothing else describes it. --}}
    <meta property="og:image:alt" content="{{ $meta->imageAlt }}">
@endif

@if ($meta->type === 'article')
    @if ($meta->publishedAt)
        <meta property="article:published_time" content="{{ $meta->publishedAt }}">
    @endif

    @if ($meta->modifiedAt)
        <meta property="article:modified_time" content="{{ $meta->modifiedAt }}">
    @endif

    @if ($meta->author)
        <meta property="article:author" content="{{ $meta->author }}">
    @endif
@endif

<meta name="twitter:card" content="{{ $meta->imageUrl ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $meta->title }}">

@if ($meta->description)
    <meta name="twitter:description" content="{{ $meta->description }}">
@endif

@if ($meta->imageUrl)
    <meta name="twitter:image" content="{{ $meta->imageUrl }}">
    <meta name="twitter:image:alt" content="{{ $meta->imageAlt }}">
@endif
