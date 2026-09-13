{{-- The plain-text alternative. Not optional: an HTML-only message scores as
     spam with most filters, and it is the only version some feature phones
     render at all. --}}
{!! $bodyText !!}

--
{{ setting('general.legal_name', setting('general.short_name', config('app.name'))) }}
{{ setting('contact.address', '') }}
@if ($unsubscribeUrl)

Unsubscribe: {{ $unsubscribeUrl }}
@if ($preferencesUrl ?? null)
Email preferences: {{ $preferencesUrl }}
@endif
@endif
