{{--
    The shell around a CMS-rendered email body.

    ── Layout, not content ─────────────────────────────────────────────────────

    Every word a reader sees comes from the template in the database or from
    the settings layer, per CLAUDE.md's CMS rule. Nothing here is a sentence
    somebody might want to change; the two link labels are the only English
    and they are translation keys.

    ── Branded from the theme, not from a hex code in a view ──────────────────

    The logo is the one the header uses; the colours are the theme tokens
    the site uses. Change the theme in the admin and the next email matches.

    ── Table-based, inline-styled, dark-mode-aware ─────────────────────────────

    Outlook and most Android mail clients strip <style> blocks and ignore
    flexbox, and this project's readers are disproportionately on low-end
    Android handsets — so the light look is inline and complete on its own.
    The <style> block is a courtesy for the clients that honour
    prefers-color-scheme (Apple Mail, iOS, some Gmail): it darkens the
    ground and lightens the text, and the brand colour is the dark-theme
    token, chosen for contrast on a dark surface.
--}}
@php
    $tokens = app(App\Support\ThemeTokens::class);
    $light = fn (string $t): string => $tokens->value($t, 'light');
    $dark = fn (string $t): string => $tokens->value($t, 'dark');
    $logo = ($logoId = setting('header.logo_light')) ? App\Models\Media::find($logoId) : null;
    $logoUrl = $logo?->isPublishable() ? $logo->getUrl() : null;
    $organisation = setting('general.legal_name', setting('general.short_name', config('app.name')));
    $registration = (string) setting('general.registration_number', '');
    $registration = str_contains($registration, '{{') ? '' : $registration;
    $address = (string) setting('contact.address', '');
    $address = str_contains($address, '{{') ? '' : $address;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $subjectLine }}</title>
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        @media (prefers-color-scheme: dark) {
            .em-ground { background-color: {{ $dark('bg') }} !important; }
            .em-card { background-color: {{ $dark('surface') }} !important; }
            .em-text, .em-text p, .em-text li, .em-text h1, .em-text h2, .em-text h3 { color: {{ $dark('text-primary') }} !important; }
            .em-muted, .em-muted a { color: {{ $dark('text-muted') }} !important; }
            .em-rule { border-top-color: {{ $dark('border') }} !important; }
            .em-brand { color: {{ $dark('brand-primary') }} !important; }
        }
    </style>
</head>
<body class="em-ground" style="margin:0;padding:0;background-color:{{ $light('surface') }};">
@if ($preheader)
    {{-- Inbox preview text. Hidden in the body; without it clients show whatever
         text comes first, which is usually a URL. --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader }}</div>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="em-ground" style="background-color:{{ $light('surface') }};">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="em-card"
                   style="max-width:600px;background-color:{{ $light('bg') }};border-radius:8px;border-top:4px solid {{ $light('brand-primary') }};">
                <tr>
                    <td style="padding:24px 24px 8px;">
                        @if ($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ $organisation }}" height="44" style="display:block;height:44px;width:auto;max-width:240px;border:0;">
                        @else
                            <span class="em-brand" style="font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:bold;color:{{ $light('brand-primary') }};">{{ $organisation }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="em-text" style="padding:16px 24px 24px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.6;color:{{ $light('text-primary') }};">
                        {!! $bodyHtml !!}
                    </td>
                </tr>
                <tr>
                    <td class="em-muted" style="padding:0 24px 24px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:{{ $light('text-muted') }};">
                        <hr class="em-rule" style="border:none;border-top:1px solid {{ $light('border') }};margin:0 0 16px;">
                        {{ $organisation }}@if ($registration) · {{ __('Reg.') }} {{ $registration }}@endif<br>
                        @if ($address){{ $address }}<br>@endif
                        @if ($unsubscribeUrl)
                            <a href="{{ $unsubscribeUrl }}" style="color:{{ $light('text-muted') }};">{{ __('Unsubscribe') }}</a>
                            @if ($preferencesUrl ?? null)
                                · <a href="{{ $preferencesUrl }}" style="color:{{ $light('text-muted') }};">{{ __('Email preferences') }}</a>
                            @endif
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
