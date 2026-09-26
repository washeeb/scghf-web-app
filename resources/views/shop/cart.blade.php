{{--
    The basket.

    Every line is its own small form — a quantity box with an "update" button
    and a "remove" button — so changing one thing changes one thing, and none
    of it needs a script. A line that can no longer be bought says so on the
    line, with the reason, rather than the checkout refusing later.
--}}
@php
    $items = $cart?->items ?? collect();
    $subtotal = $cart?->subtotal();
    $coupon = $cart?->coupon;
    $discount = ($coupon && $subtotal) ? $coupon->discountFor($subtotal) : null;
@endphp

<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="__('Your basket')">
    <div class="max-w-3xl space-y-6">
        <x-site.status />

        @error('checkout')
            <p role="alert" class="rounded-md border border-[var(--danger)] px-4 py-3 text-sm text-[var(--text-primary)]">{{ $message }}</p>
        @enderror

        @error('quantity')
            <p role="alert" class="rounded-md border border-[var(--danger)] px-4 py-3 text-sm text-[var(--text-primary)]">{{ $message }}</p>
        @enderror

        @if ($items->isEmpty())
            <p class="text-[var(--text-secondary)]">
                {{ __('There is nothing in your basket yet.') }}
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('shop.index') }}">{{ __('Browse the shop') }}</a>.
            </p>
        @else
            <ul role="list" class="divide-y divide-[var(--border)] border-y border-[var(--border)]">
                @foreach ($items as $item)
                    @php
                        $variant = $item->variant;
                        $product = $variant?->product;
                        $unavailable = ! $item->isAvailable();
                    @endphp

                    <li class="flex flex-wrap items-start gap-4 py-4 sm:flex-nowrap">
                        @if ($product?->featuredImage)
                            <x-media.image :media="$product->featuredImage" size="thumb" class="size-20 shrink-0 rounded-md object-cover" />
                        @endif

                        <div class="min-w-0 flex-1">
                            <h2 class="font-semibold text-[var(--text-primary)]">
                                @if ($product)
                                    <a href="{{ route('shop.show', $product) }}" class="hover:text-[var(--brand-primary)]">{{ $product->name }}</a>
                                @else
                                    {{ __('An item no longer on sale') }}
                                @endif
                            </h2>

                            @if ($variant?->name)
                                <p class="text-sm text-[var(--text-secondary)]">{{ $variant->name }}</p>
                            @endif

                            @if ($variant)
                                <p class="mt-1 text-sm text-[var(--text-secondary)]">{{ $variant->price->format() }} {{ __('each') }}</p>
                            @endif

                            @if ($unavailable)
                                <p class="mt-1 text-sm font-semibold text-[var(--danger)]">
                                    {{ $variant && $variant->sellableQuantity() > 0
                                        ? __('Only :count left — reduce the quantity to continue.', ['count' => $variant->sellableQuantity()])
                                        : __('No longer available — remove it to continue.') }}
                                </p>
                            @endif
                        </div>

                        @if ($variant)
                            <form method="POST" action="{{ route('shop.cart.update', $variant) }}" class="flex items-center gap-2">
                                @csrf
                                @method('PATCH')

                                <label for="qty-{{ $item->getKey() }}" class="sr-only">{{ __('Quantity of :item', ['item' => $variant->displayName()]) }}</label>
                                <input
                                    id="qty-{{ $item->getKey() }}"
                                    type="number"
                                    name="quantity"
                                    value="{{ $item->quantity }}"
                                    min="0"
                                    max="99"
                                    inputmode="numeric"
                                    class="w-20 rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-1.5 text-[var(--text-primary)]"
                                >

                                <button type="submit" class="rounded-md border border-[var(--border)] px-3 py-1.5 text-sm text-[var(--text-primary)]">{{ __('Update') }}</button>
                            </form>

                            <form method="POST" action="{{ route('shop.cart.remove', $variant) }}">
                                @csrf
                                @method('DELETE')

                                <button type="submit" class="text-sm text-[var(--text-secondary)] underline hover:text-[var(--danger)]">
                                    {{ __('Remove') }}<span class="sr-only"> {{ $variant->displayName() }}</span>
                                </button>
                            </form>

                            <p class="w-full text-right font-semibold text-[var(--text-primary)] sm:w-24">{{ $item->lineTotal()->format() }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="grid gap-8 sm:grid-cols-2">
                <div>
                    @if ($coupon)
                        <p class="text-sm text-[var(--text-secondary)]">
                            {{ __('Code :code applied.', ['code' => $coupon->code]) }}
                        </p>

                        <form method="POST" action="{{ route('shop.cart.coupon.remove') }}" class="mt-1">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm text-[var(--text-secondary)] underline">{{ __('Remove code') }}</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('shop.cart.coupon') }}" class="space-y-2">
                            @csrf

                            <label for="code" class="block text-sm font-medium text-[var(--text-primary)]">{{ __('Discount code') }}</label>

                            <div class="flex gap-2">
                                <input
                                    id="code"
                                    type="text"
                                    name="code"
                                    value="{{ old('code') }}"
                                    autocomplete="off"
                                    autocapitalize="characters"
                                    @error('code') aria-invalid="true" aria-describedby="code-error" @enderror
                                    class="w-40 rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-1.5 uppercase text-[var(--text-primary)]"
                                >
                                <button type="submit" class="rounded-md border border-[var(--border)] px-3 py-1.5 text-sm text-[var(--text-primary)]">{{ __('Apply') }}</button>
                            </div>

                            @error('code')
                                <p id="code-error" role="alert" class="text-sm text-[var(--danger)]">{{ $message }}</p>
                            @enderror
                        </form>
                    @endif
                </div>

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-[var(--text-secondary)]">{{ __('Subtotal') }}</dt>
                        <dd class="font-semibold text-[var(--text-primary)]">{{ $subtotal->format() }}</dd>
                    </div>

                    @if ($discount?->isPositive())
                        <div class="flex justify-between gap-4">
                            <dt class="text-[var(--text-secondary)]">{{ __('Discount') }}</dt>
                            <dd class="font-semibold text-[var(--text-primary)]">− {{ $discount->format() }}</dd>
                        </div>
                    @endif

                    <div class="flex justify-between gap-4 text-[var(--text-muted)]">
                        <dt>{{ __('Delivery') }}</dt>
                        <dd>{{ __('worked out at checkout') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--border)] pt-6">
                <a class="text-sm font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('shop.index') }}">{{ __('Continue shopping') }}</a>

                @if ($cart->isCheckoutable())
                    <a
                        href="{{ route('shop.checkout') }}"
                        class="rounded-md bg-[var(--brand-primary)] px-6 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                    >{{ __('Go to checkout') }}</a>
                @else
                    <p class="text-sm text-[var(--text-secondary)]">{{ __('Fix the items marked above to continue.') }}</p>
                @endif
            </div>
        @endif
    </div>
</x-site.page-shell>
