{{--
    Test mode versus live mode, on every admin page.

    Live says nothing: a permanent "everything is fine" strip trains people to
    stop reading strips. Anything else gets a band nobody can miss, so a
    trustee never reports sandbox gifts as income.

    Styled inline rather than with utility classes: the admin panel ships
    Filament's own compiled stylesheet, which contains only the classes
    Filament uses, and a band that depended on `bg-amber-400` rendered as a
    line of unstyled text (found in Phase 16 by the screenshot run).
--}}
@unless ($mode->isLive())
    @php
        $colours = $mode === \App\Payments\PaymentMode::Test
            ? 'background:#fbbf24;color:#451a03;'
            : 'background:#1f2937;color:#ffffff;';
    @endphp
    <div
        role="status"
        data-payment-mode="{{ $mode->value }}"
        style="{{ $colours }}display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:0.25rem 0.5rem;padding:0.375rem 1rem;text-align:center;font-size:0.875rem;line-height:1.25rem;font-weight:500;"
    >
        <span style="display:inline-flex;align-items:center;border:1px solid currentColor;border-radius:9999px;padding:0.125rem 0.5rem;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.025em;">
            {{ $mode->label() }}
        </span>
        <span>{{ $mode->explanation() }}</span>
    </div>
@endunless
