{{--
    Packing slips, one per page.

    For the person with the box and the tape: what goes in, where it goes,
    the number to call, the customer's notes, and a line per item to tick.
    No prices — a slip in a box that turns out to be a present should not
    say what it cost. Gift lines and downloads are not on it; they are not
    packed.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Packing slips') }}</title>
    <style>
        @page { margin: 18mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #0F1A17; line-height: 1.45; }
        h1 { font-size: 16pt; margin: 0 0 2pt; color: #0B4D3F; }
        h2 { font-size: 12pt; margin: 16pt 0 6pt; color: #0B4D3F; }
        .muted { color: #5E706B; font-size: 9.5pt; }
        .big { font-size: 14pt; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 5pt 6pt; vertical-align: top; text-align: left; }
        .lines th { border-bottom: 1px solid #0B4D3F; color: #5E706B; font-weight: normal; font-size: 9.5pt; }
        .lines td { border-bottom: 1px solid #E2E8E5; }
        .tick { width: 14pt; height: 14pt; border: 1px solid #0F1A17; display: inline-block; }
        .box { border: 1px solid #E2E8E5; padding: 10pt 12pt; margin-top: 10pt; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .logo { height: 40pt; }
    </style>
</head>
<body>
    @foreach ($orders as $order)
        @php
            $lines = $order->items->filter(fn ($item) => $item->product?->requiresDelivery() ?? true);
        @endphp
        <div class="page">
            <table>
                <tr>
                    <td style="width: 55%; padding: 0;">
                        @if ($logoPath)
                            <img class="logo" src="{{ $logoPath }}" alt="">
                        @endif
                        <h1>{{ $organisation['name'] }}</h1>
                        <div class="muted">{{ __('Packing slip') }}</div>
                    </td>
                    <td style="width: 45%; padding: 0; text-align: right;">
                        <div class="big">{{ $order->reference }}</div>
                        <div class="muted">{{ __('Ordered') }} {{ $order->created_at->format('j F Y') }}@if ($order->paid_at) · {{ __('paid') }} {{ $order->paid_at->format('j M') }}@endif</div>
                        <div class="muted">{{ $order->status->label() }}</div>
                    </td>
                </tr>
            </table>

            <table style="margin-top: 12pt;">
                <tr>
                    <td style="width: 50%; padding: 0 8pt 0 0;">
                        <div class="muted">{{ $order->is_pickup ? __('For collection by') : __('Deliver to') }}</div>
                        <div class="big">{{ $order->delivery_name ?: $order->customer_name }}</div>
                        <div>{{ $order->delivery_phone ?: $order->customer_phone }}</div>
                        @if ($order->is_pickup)
                            <div class="muted">{{ $order->shippingZone?->pickup_address }}</div>
                        @else
                            <div>{{ $order->delivery_address }}</div>
                            @if ($order->delivery_landmark)<div>{{ __('Near') }} {{ $order->delivery_landmark }}</div>@endif
                            <div>{{ collect([$order->delivery_area, $order->delivery_city, $order->delivery_region])->filter()->implode(', ') }}</div>
                            @if ($order->delivery_gps)<div class="big">{{ $order->delivery_gps }}</div>@endif
                        @endif
                    </td>
                    <td style="width: 50%; padding: 0;">
                        <div class="muted">{{ __('Method') }}</div>
                        <div>{{ $order->shipping_method ?: ($order->is_pickup ? __('Collection') : __('Delivery')) }}</div>
                        @if ($order->delivery_notes)
                            <div class="muted" style="margin-top: 6pt;">{{ __('Customer notes') }}</div>
                            <div>{{ $order->delivery_notes }}</div>
                        @endif
                    </td>
                </tr>
            </table>

            <h2>{{ __('In the box') }}</h2>
            <table class="lines">
                <thead>
                    <tr><th style="width: 24pt;"></th><th>{{ __('Item') }}</th><th>{{ __('SKU') }}</th><th style="text-align: right;">{{ __('Qty') }}</th></tr>
                </thead>
                <tbody>
                    @foreach ($lines as $item)
                        <tr>
                            <td><span class="tick"></span></td>
                            <td>{{ $item->product_name }}@if ($item->variant_name) — {{ $item->variant_name }}@endif</td>
                            <td class="muted">{{ $item->sku }}</td>
                            <td style="text-align: right;" class="big">{{ $item->quantity }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="box muted">
                {{ __('Packed by') }} ________________ &nbsp; {{ __('Date') }} ____________ &nbsp; {{ __('Parcels') }} ____
            </div>
        </div>
    @endforeach
</body>
</html>
