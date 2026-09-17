{{--
    One product, in a list.

    The price and the "sold out" state are on the card so a customer does not
    have to open three pages to find something they can actually buy. What the
    purchase funds is on the card too — it is the reason to buy here rather
    than at a market stall.

    @param product an App\Models\Product with variants loaded
    @param eager   true only for the images above the fold
--}}
@props(['product', 'eager' => false, 'level' => 'h3'])

@php
    $from = $product->fromPrice();
    $inStock = $product->isInStock();
    $variants = $product->variants->where('is_active', true);
@endphp

<div class="flex h-full flex-col rounded-lg border border-[var(--border)] p-4">
    <a
        href="{{ route('shop.show', $product) }}"
        class="group focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
    >
        @if ($product->featuredImage)
            <x-media.image
                :media="$product->featuredImage"
                size="card"
                :eager="$eager"
                class="mb-3 aspect-square w-full rounded-md object-cover"
            />
        @else
            <div class="mb-3 aspect-square w-full rounded-md bg-[var(--surface-sunken)]" aria-hidden="true"></div>
        @endif

        <{{ $level }} class="font-semibold text-[var(--text-primary)] group-hover:text-[var(--brand-primary)]">
            {{ $product->name }}
        </{{ $level }}>
    </a>

    @if ($product->cause)
        <p class="mt-1 text-xs text-[var(--text-muted)]">
            {{ __('Proceeds fund :appeal', ['appeal' => $product->cause->title]) }}
        </p>
    @endif

    <div class="mt-3 flex flex-1 items-end justify-between gap-3">
        <p class="font-semibold text-[var(--text-primary)]">
            @if ($from)
                {{ $variants->count() > 1 ? __('From :price', ['price' => $from->format()]) : $from->format() }}
            @endif
        </p>

        @unless ($inStock)
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">{{ __('Sold out') }}</p>
        @endunless
    </div>
</div>
