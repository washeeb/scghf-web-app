{{-- The plain-text alternative. Not optional: an HTML-only message scores as
     spam with most filters, and it is the only version some feature phones
     render at all. --}}
{!! $bodyText !!}

--
{{ setting('organisation.legal_name', setting('general.site_name', '')) }}
{{ setting('contact.address', '') }}
@if ($unsubscribeUrl)

Unsubscribe: {{ $unsubscribeUrl }}
@endif
