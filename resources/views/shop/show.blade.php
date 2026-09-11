{{--
    One product.

    ── Choosing a variant is a radio list, not a script ────────────────────────

    Size and colour are real radio buttons with the price and the stock state
    beside each one. A dropdown driven by JavaScript that reveals the price
    after a choice is a page that, with the script blocked or still loading on
    a slow connection, sells nothing.

    ── The description is the one place stored HTML is printed unescaped ──────

    It comes from the rich-text editor, which only staff can reach. That is
    what makes `{!! !!}` acceptable here — a page that printed customer-typed
    HTML this way would be stored XSS.
--}}
@php
    $variants = $product->variants;
    $single = $variants->count() === 1 ? $variants->first() : null;
    $selected = old('variant', $single?->ulid ?? $variants->first(fn ($v) => $v->isSellable())?->ulid);
    $gallery = $product->images->filter(fn ($image) => $image->media?->isPublishable());
@endphp

<x-layouts.app :meta="$meta">
    <x-site.breadcrumbs :crumbs="$crumbs" />

    <article class="mx-auto max-w-6xl px-4 pb-16 pt-6">
        <div class="grid gap-10 lg:grid-cols-2">
            <div>
                @if ($product->featuredImage)
                    <x-media.image
                        :media="$product->featuredImage"
                        size="hero"
                        :eager="true"
                        class="aspect-square w-full rounded-lg object-cover"
                    />
                @endif

                @if ($gallery->isNotEmpty())
                    <ul role="list" class="mt-4 grid grid-cols-4 gap-3">
                        @foreach ($gallery as $image)
                            <li>
                                <x-media.image
                                    :media="$image->media"
                                    size="thumb"
                                    class="aspect-square w-full rounded-md object-cover"
                                />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div>
                @if ($product->category)
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">
                        <a href="{{ route('shop.category', $product->category) }}" class="hover:text-[var(--brand-primary)]">{{ $product->category->name }}</a>
                    </p>
                @endif

                <h1 class="mt-1 text-3xl font-bold tracking-tight text-[var(--text-primary)]">{{ $product->name }}</h1>

                @if ($product->summary)
                    <p class="mt-3 text-lg text-[var(--text-secondary)]">{{ $product->summary }}</p>
                @endif

                @if ($product->cause)
                    <p class="mt-4 rounded-md border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-secondary)]">
                        {{ __('Proceeds from this go to') }}
                        <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('causes.show', $product->cause) }}">{{ $product->cause->title }}</a>.
                    </p>
                @endif

                @if ($variants->isEmpty())
                    <p class="mt-6 text-[var(--text-secondary)]">{{ __('This is not available to buy at the moment.') }}</p>
                @else
                    <form method="POST" action="{{ route('shop.cart.add') }}" class="mt-6 space-y-5">
                        @csrf

                        @if ($single)
                            <input type="hidden" name="variant" value="{{ $single->ulid }}">

                            <p class="text-2xl font-semibold text-[var(--text-primary)]">
                                {{ $single->price->format() }}
                                @if ($single->compare_at_price && $single->compare_at_price->greaterThan($single->price))
                                    <s class="ml-2 text-base font-normal text-[var(--text-muted)]">{{ $single->compare_at_price->format() }}</s>
                                @endif
                            </p>

                            @unless ($single->isSellable())
                                <p class="font-semibold text-[var(--text-muted)]">{{ __('Sold out') }}</p>
                            @endunless
                        @else
                            <fieldset>
                                <legend class="text-sm font-medium text-[var(--text-primary)]">{{ __('Choose an option') }}</legend>

                                <ul role="list" class="mt-2 space-y-2">
                                    @foreach ($variants as $variant)
                                        <li>
                                            <label @class(['flex cursor-pointer items-center justify-between gap-3 rounded-md border border-[var(--border)] px-4 py-3 has-[:checked]:border-[var(--brand-primary)]', 'opacity-60' => ! $variant->isSellable()])>
                                                <span class="flex items-center gap-3">
                                                    <input
                                                        type="radio"
                                                        name="variant"
                                                        value="{{ $variant->ulid }}"
                                                        @checked($selected === $variant->ulid)
                                                        @disabled(! $variant->isSellable())
                                                        class="size-4 accent-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                                                    >
                                                    <span class="text-[var(--text-primary)]">{{ $variant->name ?: $product->name }}</span>
                                                </span>

                                                <span class="text-right text-sm">
                                                    <span class="font-semibold text-[var(--text-primary)]">{{ $variant->price->format() }}</span>
                                                    @unless ($variant->isSellable())
                                                        <span class="block text-xs uppercase tracking-wide text-[var(--text-muted)]">{{ __('Sold out') }}</span>
                                                    @endunless
                                                </span>
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>

                                @error('variant')
                                    <p role="alert" class="mt-2 text-sm text-[var(--danger)]">{{ $message }}</p>
                                @enderror
                            </fieldset>
                        @endif

                        @if ($product->isInStock())
                            <div class="flex flex-wrap items-end gap-4">
                                <div>
                                    <label for="quantity" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Quantity') }}</label>
                                    <input
                                        id="quantity"
                                        type="number"
                                        name="quantity"
                                        value="{{ old('quantity', 1) }}"
                                        min="1"
                                        max="99"
                                        inputmode="numeric"
                                        @error('quantity') aria-invalid="true" aria-describedby="quantity-error" @enderror
                                        class="w-24 rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                                    >
                                </div>

                                <button
                                    type="submit"
                                    class="rounded-md bg-[var(--brand-primary)] px-6 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                                >{{ __('Add to basket') }}</button>
                            </div>

                            @error('quantity')
                                <p id="quantity-error" role="alert" class="text-sm text-[var(--danger)]">{{ $message }}</p>
                            @enderror
                        @endif
                    </form>
                @endif

                @if ($product->description)
                    <div class="prose-scghf mt-8 space-y-4 text-[var(--text-primary)]">{!! $product->description !!}</div>
                @endif

                @if ($product->isDigital())
                    <p class="mt-6 text-sm text-[var(--text-muted)]">{{ __('A download. Nothing is posted — the link arrives by email once the payment is confirmed.') }}</p>
                @endif
            </div>
        </div>

        @if ($related->isNotEmpty())
            <section class="mt-16" aria-labelledby="related-heading">
                <h2 id="related-heading" class="text-xl font-bold text-[var(--text-primary)]">{{ __('You might also like') }}</h2>

                <ul role="list" class="mt-6 grid grid-cols-2 gap-4 sm:gap-6 lg:grid-cols-3">
                    @foreach ($related as $other)
                        <li><x-site.product-card :product="$other" /></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </article>
</x-layouts.app>
