{{--
    One delivery, from the courier's phone.

    The address, a call button, the parcel's contents, and the next step as
    one large button. "Delivered" asks who took it and offers a photograph;
    the phone's position goes along when the browser gives it (courier.js),
    and nothing breaks when it does not.
--}}
@php
    use App\Models\Delivery;
    $phone = $order->delivery_phone ?: $order->customer_phone;
    $mapQuery = $order->delivery_gps ?: trim($address);
@endphp

<x-courier.layout :title="$order->delivery_name ?: $order->customer_name">
    <p><a href="{{ route('courier.index') }}" class="text-sm text-[var(--brand-primary)] hover:underline">&larr; {{ setting('courier.back_label', __('All deliveries')) }}</a></p>

    <section class="rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow-sm)]">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm text-[var(--text-muted)]">{{ $order->reference }}</p>
            <span class="rounded-full bg-[var(--surface-sunken)] px-2.5 py-1 text-xs font-semibold text-[var(--text-primary)]">{{ $delivery->label() }}</span>
        </div>

        <address class="mt-3 space-y-1 text-base not-italic text-[var(--text-primary)]">
            <p class="font-semibold">{{ $order->delivery_name ?: $order->customer_name }}</p>
            <p>{{ $address ?: __('No address recorded') }}</p>
            @if ($order->delivery_notes)
                <p class="text-sm text-[var(--text-secondary)]">{{ __('Note') }}: {{ $order->delivery_notes }}</p>
            @endif
        </address>

        <div class="mt-4 flex flex-wrap gap-2">
            @if ($phone)
                <a href="tel:{{ preg_replace('/\s+/', '', $phone) }}" class="btn btn-outline btn-sm">
                    <x-ui.icon name="phone" class="size-4" /> {{ setting('courier.call_label', __('Call')) }} {{ $phone }}
                </a>
            @endif
            @if ($mapQuery)
                <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($mapQuery) }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm">
                    <x-ui.icon name="map-pin" class="size-4" /> {{ setting('courier.map_label', __('Map')) }}
                </a>
            @endif
        </div>

        @if ($delivery->office_notes)
            <p class="mt-4 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] px-3 py-2 text-sm text-[var(--text-secondary)]"><span class="font-semibold">{{ __('From the office') }}:</span> {{ $delivery->office_notes }}</p>
        @endif

        @if ($delivery->status === Delivery::STATUS_FAILED && $delivery->failure_reason)
            <p class="mt-4 rounded-[var(--radius-md)] border border-[var(--danger)] px-3 py-2 text-sm text-[var(--text-primary)]"><span class="font-semibold">{{ __('Last attempt') }}:</span> {{ $delivery->failure_reason }}</p>
        @endif
    </section>

    <section class="rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-4">
        <h2 class="font-heading text-sm font-semibold uppercase tracking-wide text-[var(--text-muted)]">{{ setting('courier.parcel_heading', __('In the parcel')) }}</h2>
        <ul class="mt-2 divide-y divide-[var(--border)] text-sm">
            @foreach ($order->items as $item)
                <li class="flex justify-between gap-3 py-2">
                    <span class="text-[var(--text-primary)]">{{ $item->quantity }} × {{ $item->product_name }}{{ $item->variant_name ? " — ".$item->variant_name : "" }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    @if ($delivery->isActive())
        <section class="space-y-3">
            @if (in_array($delivery->status, [Delivery::STATUS_ASSIGNED, Delivery::STATUS_FAILED], true))
                <form method="POST" action="{{ route('courier.picked-up', $delivery) }}">
                    @csrf
                    <button type="submit" class="btn btn-brand w-full py-4 text-base">{{ setting('courier.picked_up_label', __('I have picked it up')) }}</button>
                </form>
            @endif

            @if (in_array($delivery->status, [Delivery::STATUS_ASSIGNED, Delivery::STATUS_PICKED_UP, Delivery::STATUS_FAILED], true))
                <form method="POST" action="{{ route('courier.out-for-delivery', $delivery) }}">
                    @csrf
                    <button type="submit" class="btn btn-brand w-full py-4 text-base">{{ setting('courier.out_for_delivery_label', __('On my way to the customer')) }}</button>
                </form>
            @endif

            {{-- Delivered: who took it, a note, a photograph, the phone's position. --}}
            <details class="rounded-[var(--radius-lg)] border border-[var(--brand-primary)] bg-[var(--surface)]" @if ($delivery->status === Delivery::STATUS_OUT_FOR_DELIVERY) open @endif>
                <summary class="cursor-pointer list-none px-4 py-4 text-center text-base font-semibold text-[var(--brand-primary)]">{{ setting('courier.delivered_label', __('Delivered — confirm')) }}</summary>
                <form method="POST" action="{{ route('courier.delivered', $delivery) }}" enctype="multipart/form-data" class="space-y-4 border-t border-[var(--border)] p-4" data-courier-delivered>
                    @csrf
                    <div>
                        <label for="recipient_name" class="block text-sm font-medium text-[var(--text-primary)]">{{ setting('courier.recipient_label', __('Who received it?')) }}</label>
                        <input id="recipient_name" name="recipient_name" type="text" required maxlength="120" value="{{ old('recipient_name', $order->delivery_name ?: $order->customer_name) }}" class="mt-1 w-full rounded-[var(--radius-md)] border border-[var(--border-interactive)] bg-[var(--bg)] px-3 py-3 text-base">
                    </div>
                    <div>
                        <label for="photo" class="block text-sm font-medium text-[var(--text-primary)]">{{ setting('courier.photo_label', __('Photo of the parcel at the door')) }}@unless ((bool) setting('courier.require_photo', false)) <span class="font-normal text-[var(--text-muted)]">({{ __('optional') }})</span>@endunless</label>
                        <input id="photo" name="photo" type="file" accept="image/*" capture="environment" @if ((bool) setting('courier.require_photo', false)) required @endif class="mt-1 w-full text-sm">
                    </div>
                    <div>
                        <label for="note" class="block text-sm font-medium text-[var(--text-primary)]">{{ setting('courier.note_label', __('Anything to note?')) }} <span class="font-normal text-[var(--text-muted)]">({{ __('optional') }})</span></label>
                        <textarea id="note" name="note" rows="2" maxlength="1000" class="mt-1 w-full rounded-[var(--radius-md)] border border-[var(--border-interactive)] bg-[var(--bg)] px-3 py-2 text-base">{{ old('note') }}</textarea>
                    </div>
                    <input type="hidden" name="lat" data-courier-lat>
                    <input type="hidden" name="lng" data-courier-lng>
                    <p class="text-xs text-[var(--text-muted)]" data-courier-location>{{ setting('courier.location_note', __('Your location is recorded with the confirmation when your phone allows it.')) }}</p>
                    <button type="submit" class="btn btn-accent w-full py-4 text-base">{{ setting('courier.confirm_label', __('Confirm delivery')) }}</button>
                </form>
            </details>

            {{-- Could not deliver. --}}
            @if ($delivery->status !== Delivery::STATUS_FAILED)
                <details class="rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)]">
                    <summary class="cursor-pointer list-none px-4 py-3 text-center text-sm font-semibold text-[var(--text-muted)]">{{ setting('courier.failed_label', __('I could not deliver')) }}</summary>
                    <form method="POST" action="{{ route('courier.failed', $delivery) }}" class="space-y-4 border-t border-[var(--border)] p-4">
                        @csrf
                        <fieldset>
                            <legend class="text-sm font-medium text-[var(--text-primary)]">{{ __('Why?') }}</legend>
                            <div class="mt-2 space-y-2">
                                @foreach ($reasons as $reason)
                                    <label class="flex items-center gap-3 rounded-[var(--radius-md)] border border-[var(--border)] px-3 py-3 text-base">
                                        <input type="radio" name="reason" value="{{ $reason }}" required class="size-5"> {{ $reason }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <div>
                            <label for="detail" class="block text-sm font-medium text-[var(--text-primary)]">{{ __('Details') }} <span class="font-normal text-[var(--text-muted)]">({{ __('optional') }})</span></label>
                            <textarea id="detail" name="detail" rows="2" maxlength="1000" class="mt-1 w-full rounded-[var(--radius-md)] border border-[var(--border-interactive)] bg-[var(--bg)] px-3 py-2 text-base"></textarea>
                        </div>
                        <button type="submit" class="btn btn-outline w-full py-3">{{ __('Record it') }}</button>
                    </form>
                </details>
            @endif
        </section>
    @else
        <section class="rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-4 text-sm">
            <p class="font-semibold text-[var(--text-primary)]">{{ $delivery->label() }}@if ($delivery->delivered_at) · {{ $delivery->delivered_at->format('j M Y, H:i') }}@endif</p>
            @if ($delivery->recipient_name)
                <p class="mt-1 text-[var(--text-secondary)]">{{ __('Received by') }} {{ $delivery->recipient_name }}</p>
            @endif
        </section>
    @endif
</x-courier.layout>
