{{--
    Checkout.

    One page, one POST, then the gateway. The delivery charge for each region
    is listed beside the form so the customer can see what their region costs
    before choosing it — without a script to update a running total — and the
    gateway shows the exact amount before taking anything.

    A Ghanaian address: region, area, and a landmark-style line. The phone
    number is what actually gets a parcel delivered, which is why it is
    required for delivery.
--}}
@php
    $regionsServed = $delivery->flatMap(fn ($option) => $option['regions'])->unique()->sort()->values();
    $fulfilment = old('fulfilment', $regionsServed->isEmpty() && $pickup ? 'collect' : 'deliver');

    /*
     * The policies that are live, as links, joined into one sentence. Built
     * here rather than with three conditional components in a row, because
     * "terms delivery policy refund policy." with no commas is what that
     * produces the moment one of them is a draft.
     */
    $policies = collect([
        'terms' => __('terms'),
        'shipping-and-delivery' => __('delivery policy'),
        'refund-policy' => __('refund policy'),
    ])->map(function (string $label, string $slug): ?string {
        $page = App\Models\Page::query()->where('slug', $slug)->whereNull('parent_id')->first();

        return $page?->isLive()
            ? '<a class="underline hover:text-[var(--brand-primary)]" href="'.e(url($page->path)).'">'.e($label).'</a>'
            : null;
    })->filter()->values();

    $policySentence = match ($policies->count()) {
        0 => null,
        1 => $policies->first(),
        default => $policies->slice(0, -1)->implode(', ').' '.__('and').' '.$policies->last(),
    };
@endphp

@push('scripts')
    <span hidden data-track-event="checkout_started" data-track-once="checkout:{{ session()->getId() }}"></span>
@endpush

<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="__('Checkout')">
    <div class="grid gap-12 lg:grid-cols-[2fr_1fr]">
        <form method="POST" action="{{ route('shop.checkout.store') }}" class="max-w-2xl space-y-8">
            @csrf
            <x-honeypot />

            <fieldset class="space-y-5">
                <legend class="text-lg font-semibold text-[var(--text-primary)]">{{ __('Your details') }}</legend>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-site.field name="customer_name" :label="__('Your name')" required autocomplete="name" :value="$prefill['customer_name']" />
                    <x-site.field name="customer_email" type="email" :label="__('Email address')" required autocomplete="email" :value="$prefill['customer_email']"
                        :hint="__('Your order confirmation goes here.')" />
                </div>

                <x-site.field name="customer_phone" type="tel" :label="__('Phone number')" autocomplete="tel" :value="$prefill['customer_phone']"
                    :hint="__('The courier calls this number to find you. 024 123 4567 or +233 24 123 4567.')" />
            </fieldset>

            @if ($cart->requiresDelivery())
            <fieldset class="space-y-5">
                <legend class="text-lg font-semibold text-[var(--text-primary)]">{{ __('Delivery or collection') }}</legend>

                @error('fulfilment')
                    <p role="alert" class="text-sm text-[var(--danger)]">{{ $message }}</p>
                @enderror

                <div class="space-y-3">
                    @if ($regionsServed->isNotEmpty())
                        <label class="flex cursor-pointer items-start gap-3 rounded-md border border-[var(--border)] p-4 has-[:checked]:border-[var(--brand-primary)]">
                            <input type="radio" name="fulfilment" value="deliver" @checked($fulfilment === 'deliver') class="mt-1 size-4 accent-[var(--brand-primary)]">
                            <span>
                                <span class="block font-semibold text-[var(--text-primary)]">{{ __('Deliver it to me') }}</span>
                                <span class="block text-sm text-[var(--text-secondary)]">{{ __('The charge depends on your region — see the delivery charges under "Your order".') }}</span>
                            </span>
                        </label>
                    @endif

                    @if ($pickup)
                        <label class="flex cursor-pointer items-start gap-3 rounded-md border border-[var(--border)] p-4 has-[:checked]:border-[var(--brand-primary)]">
                            <input type="radio" name="fulfilment" value="collect" @checked($fulfilment === 'collect') class="mt-1 size-4 accent-[var(--brand-primary)]">
                            <span>
                                <span class="block font-semibold text-[var(--text-primary)]">{{ __('I will collect it') }} — {{ __('free') }}</span>
                                @if ($pickup->description)
                                    <span class="block text-sm text-[var(--text-secondary)]">{{ $pickup->description }}</span>
                                @endif
                                @if ($pickup->pickup_address)
                                    <span class="block text-sm text-[var(--text-secondary)]">{{ $pickup->pickup_address }}</span>
                                @endif
                                @if ($pickup->pickup_hours)
                                    <span class="block text-sm text-[var(--text-muted)]">{{ $pickup->pickup_hours }}</span>
                                @endif
                            </span>
                        </label>
                    @endif
                </div>

                @if ($regionsServed->isNotEmpty())
                    <div class="space-y-5 rounded-md border border-[var(--border)] p-4">
                        <p class="text-sm font-medium text-[var(--text-primary)]">{{ __('Where to deliver') }} <span class="font-normal text-[var(--text-muted)]">({{ __('for delivery only') }})</span></p>

                        <div>
                            <label for="delivery_region" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Region') }}</label>
                            <select
                                id="delivery_region"
                                name="delivery_region"
                                autocomplete="address-level1"
                                @error('delivery_region') aria-invalid="true" aria-describedby="delivery_region-error" @enderror
                                class="w-full max-w-md rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)]"
                            >
                                <option value="">{{ __('Choose a region') }}</option>
                                @foreach ($regionsServed as $region)
                                    <option value="{{ $region }}" @selected(old('delivery_region') === $region)>{{ $region }}</option>
                                @endforeach
                            </select>

                            @error('delivery_region')
                                <p id="delivery_region-error" role="alert" class="mt-1 text-sm text-[var(--danger)]">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-site.field name="delivery_city" :label="__('Town or district')" autocomplete="address-level2" :hint="__('Tamale, Bolgatanga, Tema…')" />
                            <x-site.field name="delivery_area" :label="__('Area or suburb')" autocomplete="address-level3" :hint="__('Madina, Sakumono, Nyankpala…')" />
                        </div>
                        <x-site.field name="delivery_address" :label="__('House, street or directions')" autocomplete="street-address"
                            :hint="__('A house number and street where there is one; otherwise directions a courier can follow.')" />
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-site.field name="delivery_landmark" :label="__('Nearest landmark')" :hint="__('Optional. Opposite the filling station, behind the market…')" />
                            <x-site.field name="delivery_gps" :label="__('GhanaPost GPS address')" placeholder="GA-184-3456" :hint="__('Optional. The digital address from the GhanaPost GPS app.')" />
                        </div>
                        <x-site.field name="delivery_notes" type="textarea" :rows="2" :label="__('Delivery notes')" :hint="__('Optional. Best time to call, a gate to use.')" />
                    </div>
                @endif
            </fieldset>
            @else
                <p class="rounded-md border border-[var(--border)] p-4 text-sm text-[var(--text-secondary)]">
                    {{ __('Nothing in this basket needs delivering. Downloads, tickets and receipts arrive by email.') }}
                </p>
            @endif

            @if ((bool) setting('shop.offer_gift_at_checkout', true) && $giftOptions !== [])
                <fieldset class="space-y-3">
                    <legend class="text-lg font-semibold text-[var(--text-primary)]">{{ __('Add a gift?') }}</legend>
                    <p class="text-sm text-[var(--text-secondary)]">{{ __('Optional. A gift on top of your order goes to our work wherever it is needed most, and is receipted separately.') }}</p>

                    <div class="flex flex-wrap gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="donation_amount" value="" @checked(old('donation_amount', '') === '') class="peer sr-only">
                            <span class="block rounded-md border border-[var(--border)] px-4 py-2 text-sm font-medium text-[var(--text-primary)] peer-checked:border-[var(--brand-primary)] peer-checked:bg-[var(--brand-primary)] peer-checked:text-[var(--text-on-brand)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--focus-ring)]">{{ __('No gift') }}</span>
                        </label>
                        @foreach ($giftOptions as $label => $amount)
                            <label class="cursor-pointer">
                                <input type="radio" name="donation_amount" value="{{ $amount->toMajorString() }}" @checked(old('donation_amount') === $amount->toMajorString()) class="peer sr-only">
                                <span class="block rounded-md border border-[var(--border)] px-4 py-2 text-sm font-medium text-[var(--text-primary)] peer-checked:border-[var(--brand-primary)] peer-checked:bg-[var(--brand-primary)] peer-checked:text-[var(--text-on-brand)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--focus-ring)]">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                    @error('donation_amount')
                        <p role="alert" class="text-sm text-[var(--danger)]">{{ $message }}</p>
                    @enderror
                </fieldset>
            @endif

            <fieldset class="space-y-4">
                <legend class="sr-only">{{ __('Agreement') }}</legend>

                <x-site.checkbox
                    name="consent"
                    required
                    :label="setting('compliance.shop_consent_text', __('I understand that my details are held in order to fulfil this order.'))"
                />

                <p class="text-xs text-[var(--text-muted)]">
                    @if ($policySentence)
                        {{ __('By paying you accept our') }} {!! $policySentence !!}.
                    @endif
                    {{ __('A purchase is not a donation and no charitable receipt is issued for it.') }}
                </p>
            </fieldset>

            @error('payment')
                <p role="alert" class="rounded-md border border-[var(--danger)] px-4 py-3 text-sm text-[var(--text-primary)]">{{ $message }}</p>
            @enderror

            <button
                type="submit"
                class="rounded-md bg-[var(--brand-primary)] px-8 py-3 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >{{ __('Continue to payment') }}</button>

            <p class="text-xs text-[var(--text-muted)]">{{ __('Payment is taken securely by Paystack. We never see your card details.') }}</p>
        </form>

        {{-- First on a phone, so the customer sees what they are paying for
             before they start typing; beside the form on a laptop. --}}
        <aside class="order-first space-y-6 lg:order-none lg:sticky lg:top-24 lg:self-start" aria-labelledby="summary-heading">
            <h2 id="summary-heading" class="text-lg font-semibold text-[var(--text-primary)]">{{ __('Your order') }}</h2>

            <ul role="list" class="divide-y divide-[var(--border)] text-sm">
                @foreach ($cart->items as $item)
                    <li class="flex justify-between gap-4 py-2">
                        <span class="text-[var(--text-primary)]">{{ $item->quantity }} × {{ $item->variant->displayName() }}</span>
                        <span class="font-semibold text-[var(--text-primary)]">{{ $item->lineTotal()->format() }}</span>
                    </li>
                @endforeach
            </ul>

            <dl class="space-y-2 border-t border-[var(--border)] pt-4 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-[var(--text-secondary)]">{{ __('Subtotal') }}</dt>
                    <dd class="font-semibold text-[var(--text-primary)]">{{ $subtotal->format() }}</dd>
                </div>

                @if ($discount?->isPositive())
                    <div class="flex justify-between gap-4">
                        <dt class="text-[var(--text-secondary)]">{{ __('Discount') }} ({{ $cart->coupon->code }})</dt>
                        <dd class="font-semibold text-[var(--text-primary)]">− {{ $discount->format() }}</dd>
                    </div>
                @endif
            </dl>

            <div class="rounded-md border border-[var(--border)] p-4 text-sm">
                <h3 class="font-semibold text-[var(--text-primary)]">{{ __('Delivery charges') }}</h3>

                <ul role="list" class="mt-2 space-y-2">
                    @foreach ($delivery as $option)
                        <li>
                            <span class="block text-[var(--text-primary)]">{{ $option['zone']->name }} — <span class="font-semibold">{{ $option['label'] }}</span></span>
                            <span class="block text-xs text-[var(--text-muted)]">{{ implode(', ', $option['regions']) }}</span>
                        </li>
                    @endforeach

                    @if ($pickup)
                        <li class="text-[var(--text-primary)]">{{ $pickup->name }} — <span class="font-semibold">{{ __('free') }}</span></li>
                    @endif

                    @if ($delivery->isEmpty() && ! $pickup)
                        <li class="text-[var(--text-secondary)]">{{ __('Delivery is not set up yet. Please contact us to arrange your order.') }}</li>
                    @endif
                </ul>

                <p class="mt-3 text-xs text-[var(--text-muted)]">{{ __('The exact total is shown on the payment page before you pay.') }}</p>
            </div>
        </aside>
    </div>
</x-site.page-shell>
