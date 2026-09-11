{{--
    A link to one of the locked policy pages — terms, privacy, refunds,
    delivery, safeguarding — rendered only when that page is LIVE.

    A form footer that links to an unpublished policy is a link to a 404 at the
    exact moment somebody is deciding whether to trust a payment form. Nothing
    at all is rendered while the page is a draft, and the caller's sentence
    should read correctly without it.

    @param slug   the page slug (top-level pages only)
    @param label  the link text; defaults to the page title
--}}
@props(['slug', 'label' => null])

@php
    $page = App\Models\Page::query()->where('slug', $slug)->whereNull('parent_id')->first();
@endphp

@if ($page?->isLive())
    <a {{ $attributes->class(['underline hover:text-[var(--brand-primary)]']) }} href="{{ url($page->path) }}">{{ $label ?? $page->title }}</a>
@endif
