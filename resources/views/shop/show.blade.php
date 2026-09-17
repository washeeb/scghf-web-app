{{--
    One product.

    ── Choosing a variant is a radio list, not a script ────────────────────────

    Size and colour are real radio buttons with the price and the stock state
    beside each one. A dropdown driven by JavaScript that reveals the price
    after a choice is a page that, with the script blocked or still loading on
    a slow connection, sells nothing.

    ── The description is the one place stored HTML is printed unescaped ──────

    It comes from the rich-text editor, which only staff can reach, and is
    sanitised on the way out by `@clean` — so a compromised staff account
    cannot turn a product page into stored XSS either.
--}}
@php
    $variants = $product->variants;
    $single = $variants->count() === 1 ? $variants->first() : null;
    $selected = old('variant', $single?->ulid ?? $variants->first(fn ($v) => $v->isSellable())?->ulid);
    $gallery = $product->images->filter(fn ($image) => $image->media?->isPublishable());
@endphp

@push('head')
    <script type="application/ld+json">{!! app(App\Support\StructuredData::class)->product($product) !!}</script>
@endpush

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
                                {{ $single->priceFor(1, auth()->check())->format() }}
                                @if ($single->compare_at_price && $single->compare_at_price->greaterThan($single->price))
                                    <s class="ml-2 text-base font-normal text-[var(--text-muted)]">{{ $single->compare_at_price->format() }}</s>
                                @endif
                            </p>

                            @foreach ($single->sortedTiers() as $tier)
                                <p class="text-sm text-[var(--text-muted)]">{{ __(':from or more: :price each', ['from' => $tier['min_quantity'], 'price' => App\ValueObjects\Money::ofMinor($tier['price_minor'], $single->currency)->format()]) }}</p>
                            @endforeach
                            @if ($single->member_price && ! auth()->check() && $single->member_price->lessThan($single->price))
                                <p class="text-sm text-[var(--text-muted)]">{{ __(':price when signed in', ['price' => $single->member_price->format()]) }}</p>
                            @endif

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
                                                    <span class="font-semibold text-[var(--text-primary)]">{{ $variant->priceFor(1, auth()->check())->format() }}</span>
                                                    @if ($variant->compare_at_price && $variant->compare_at_price->greaterThan($variant->price))
                                                        <s class="ml-1 text-xs text-[var(--text-muted)]">{{ $variant->compare_at_price->format() }}</s>
                                                    @endif
                                                    @foreach ($variant->sortedTiers() as $tier)
                                                        <span class="block text-xs text-[var(--text-muted)]">{{ __(':from or more: :price each', ['from' => $tier['min_quantity'], 'price' => App\ValueObjects\Money::ofMinor($tier['price_minor'], $variant->currency)->format()]) }}</span>
                                                    @endforeach
                                                    @if ($variant->member_price && ! auth()->check() && $variant->member_price->lessThan($variant->price))
                                                        <span class="block text-xs text-[var(--text-muted)]">{{ __(':price when signed in', ['price' => $variant->member_price->format()]) }}</span>
                                                    @endif
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
                    <div class="prose-scghf mt-8 space-y-4 text-[var(--text-primary)]">@clean($product->description)</div>
                @endif

                @if (filled($product->specifications))
                    <section class="mt-8" aria-labelledby="specs-heading">
                        <h2 id="specs-heading" class="text-lg font-semibold text-[var(--text-primary)]">{{ __('Details') }}</h2>
                        <dl class="mt-3 divide-y divide-[var(--border)] border-y border-[var(--border)] text-sm">
                            @foreach ($product->specifications as $label => $value)
                                <div class="grid grid-cols-3 gap-4 py-2">
                                    <dt class="text-[var(--text-muted)]">{{ $label }}</dt>
                                    <dd class="col-span-2 text-[var(--text-primary)]">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endif

                @if ($product->isDigital())
                    <p class="mt-6 text-sm text-[var(--text-muted)]">{{ __('A download. Nothing is posted — the link arrives by email once the payment is confirmed.') }}</p>
                @elseif ($product->isDonation())
                    <p class="mt-6 text-sm text-[var(--text-muted)]">{{ __('A gift, not goods. Nothing is posted: when you pay, this becomes a donation to :cause and you receive a receipt for it.', ['cause' => $product->cause?->title ?? __('our work')]) }}</p>
                @elseif ($product->isTicket() && $product->eventTicket?->event)
                    <p class="mt-6 text-sm text-[var(--text-muted)]">{{ __('A ticket to :event on :date. Your codes arrive by email once the payment is confirmed; show one at the door.', ['event' => $product->eventTicket->event->title, 'date' => $product->eventTicket->event->starts_at?->format('j F Y')]) }}</p>
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
