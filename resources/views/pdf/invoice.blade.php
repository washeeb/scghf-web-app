{{--
    The invoice, as a PDF.

    Rendered from the INVOICE ROW — frozen at issue — with the goods lines
    from the order. Gift lines are not here: they are receipted. Inline
    styles and tables on purpose; DomPDF speaks a subset of CSS.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 20mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #0F1A17; line-height: 1.45; }
        h1 { font-size: 18pt; margin: 0 0 4pt; color: #0B4D3F; }
        h2 { font-size: 12pt; margin: 18pt 0 6pt; color: #0B4D3F; }
        .muted { color: #5E706B; font-size: 9.5pt; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 5pt 6pt; vertical-align: top; text-align: left; }
        .lines th { border-bottom: 1px solid #0B4D3F; color: #5E706B; font-weight: normal; font-size: 9.5pt; }
        .lines td { border-bottom: 1px solid #E2E8E5; }
        .num { text-align: right; white-space: nowrap; }
        .totals td { padding: 3pt 6pt; }
        .total { font-size: 13pt; font-weight: bold; }
        .box { border: 1px solid #E2E8E5; padding: 10pt 12pt; margin-top: 12pt; }
        .footer { margin-top: 24pt; font-size: 8.5pt; color: #5E706B; border-top: 1px solid #E2E8E5; padding-top: 8pt; }
        .logo { height: 46pt; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td style="width: 60%; padding: 0;">
                @if ($logoPath)
                    <img class="logo" src="{{ $logoPath }}" alt="">
                @endif
                <h1>{{ $organisation['name'] }}</h1>
                <div class="muted">
                    @if ($organisation['registration'] && ! str_contains($organisation['registration'], '{{'))
                        {{ __('Registration no.') }} {{ $organisation['registration'] }}<br>
                    @endif
                    @if ($organisation['tin'])
                        TIN {{ $organisation['tin'] }}<br>
                    @endif
                    @if ($organisation['address'])
                        {{ $organisation['address'] }}<br>
                    @endif
                    {{ collect([$organisation['email'], $organisation['phone']])->filter(fn ($v) => $v && ! str_contains($v, '{{'))->implode(' · ') }}
                </div>
            </td>
            <td style="width: 40%; padding: 0; text-align: right;">
                <div class="muted">{{ __('Invoice') }}</div>
                <div style="font-size: 13pt; font-weight: bold;">{{ $invoice->invoice_number }}</div>
                <div class="muted">{{ __('Issued') }} {{ $invoice->issued_on->format('j F Y') }}</div>
                <div class="muted">{{ __('Order') }} {{ $order->reference }}</div>
            </td>
        </tr>
    </table>

    <h2>{{ __('Invoiced to') }}</h2>
    <div>{{ $invoice->customer_name }}@if ($invoice->customer_email)<br><span class="muted">{{ $invoice->customer_email }}</span>@endif</div>
    @if ($order->requiresDelivery())
        <div class="muted" style="margin-top: 4pt;">{{ $order->is_pickup ? __('Collected') : __('Delivered to') }}: {{ $order->deliveryAddressLine() }}</div>
    @endif

    <h2>{{ __('Goods') }}</h2>
    <table class="lines">
        <thead>
            <tr><th>{{ __('Item') }}</th><th class="num">{{ __('Qty') }}</th><th class="num">{{ __('Each') }}</th><th class="num">{{ __('Total') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($lines as $item)
                <tr>
                    <td>{{ $item->product_name }}@if ($item->variant_name) — {{ $item->variant_name }}@endif<br><span class="muted">{{ $item->sku }}</span></td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ $item->unit_price->format() }}</td>
                    <td class="num">{{ $item->line_total->format() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 8pt;">
        <tr><td style="width: 70%;"></td><td class="muted">{{ __('Subtotal') }}</td><td class="num">{{ $invoice->subtotal->format() }}</td></tr>
        @if ($invoice->discount->isPositive())
            <tr><td></td><td class="muted">{{ __('Discount') }}@if ($order->coupon_code) ({{ $order->coupon_code }})@endif</td><td class="num">− {{ $invoice->discount->format() }}</td></tr>
        @endif
        @if ($order->requiresDelivery())
            <tr><td></td><td class="muted">{{ $order->is_pickup ? __('Collection') : __('Delivery') }}@if ($order->shipping_method) ({{ $order->shipping_method }})@endif</td><td class="num">{{ $invoice->shipping->isZero() ? __('free') : $invoice->shipping->format() }}</td></tr>
        @endif
        <tr><td></td><td class="total">{{ __('Total') }}</td><td class="num total">{{ $invoice->total->format() }}</td></tr>
        <tr><td></td><td colspan="2" class="muted">{{ $invoice->total_in_words }}</td></tr>
    </table>

    @if ($order->paid_at)
        <p class="muted" style="margin-top: 8pt;">{{ __('Paid :date, reference :reference.', ['date' => $order->paid_at->format('j F Y'), 'reference' => $order->paystack_reference ?? $order->reference]) }}</p>
    @endif

    <div class="box">
        <p style="margin: 0;">{{ $invoice->statement }}</p>
    </div>

    <div class="footer">
        {{ __('This document was generated by :name and can be verified by quoting the invoice number.', ['name' => $organisation['name']]) }}
        <br>{{ $organisation['website'] }}
    </div>
</body>
</html>
