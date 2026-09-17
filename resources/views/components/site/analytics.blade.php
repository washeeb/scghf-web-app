{{--
    The analytics script, inert until allowed.

    `type="text/plain"` is not executed by any browser. Phase 12's
    cookie-consent.js swaps it for a real script only when the Analytics
    category is on — so nothing from Plausible, Umami or Google loads,
    connects or sets anything before the visitor said yes. The nonce is
    copied onto the live script, which is what the CSP checks.
--}}
@if (App\Support\Analytics::enabled())
    @php($provider = App\Support\Analytics::provider())
    @if ($provider === 'plausible')
        <script type="text/plain" data-consent="analytics" nonce="{{ $cspNonce ?? '' }}" defer data-domain="{{ App\Support\Analytics::siteId() }}" src="{{ App\Support\Analytics::scriptUrl() }}"></script>
    @elseif ($provider === 'umami')
        <script type="text/plain" data-consent="analytics" nonce="{{ $cspNonce ?? '' }}" defer data-website-id="{{ App\Support\Analytics::siteId() }}" src="{{ App\Support\Analytics::scriptUrl() }}"></script>
    @elseif ($provider === 'ga4')
        <script type="text/plain" data-consent="analytics" nonce="{{ $cspNonce ?? '' }}" async src="{{ App\Support\Analytics::scriptUrl() }}"></script>
        <script type="text/plain" data-consent="analytics" nonce="{{ $cspNonce ?? '' }}">
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('consent', 'default', {analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied'});
            gtag('js', new Date());
            gtag('config', @json(App\Support\Analytics::siteId()), {anonymize_ip: true});
        </script>
    @endif
@endif

{{-- A conversion the server knows about, fired once on this page. --}}
@if ($tracked = session('track'))
    <span hidden data-track-event="{{ $tracked['event'] }}" data-track-props="{{ json_encode($tracked['props'] ?? []) }}" data-track-once="{{ $tracked['key'] ?? $tracked['event'] }}"></span>
@endif
