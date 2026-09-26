{{--
    Address, phone and email, from the settings layer.

    Never typed into the block. A contact block holding its own copy of the
    phone number is a number that stays wrong after somebody changes it in
    settings — and the whole point of the CMS rule is that there is one place.

    Each detail is omitted when unfilled rather than rendering an empty label.
--}}
<x-blocks.section :section="$section" :eyebrow="$section->field('eyebrow')" :heading="$section->field('heading')">
    <address class="not-italic">
        <ul class="space-y-3 text-[var(--text-primary)]">
            @if ($address = setting('contact.address'))
                <li>{{ $address }}</li>
            @endif

            @if ($gps = setting('contact.gps_address'))
                {{-- Frequently the only way to find a Ghanaian address. --}}
                <li>{{ __('GPS') }}: {{ $gps }}</li>
            @endif

            @if ($phone = setting('contact.phone_primary'))
                <li><a class="hover:underline" href="tel:{{ preg_replace('/\s+/', '', $phone) }}">{{ $phone }}</a></li>
            @endif

            @if ($whatsapp = setting('contact.whatsapp'))
                <li>{{ __('WhatsApp') }}: {{ $whatsapp }}</li>
            @endif

            @if ($email = setting('contact.email_general'))
                <li><a class="hover:underline" href="mailto:{{ $email }}">{{ $email }}</a></li>
            @endif

            @if ($hours = setting('contact.office_hours'))
                <li class="text-[var(--text-muted)]">{{ $hours }}</li>
            @endif
        </ul>
    </address>
</x-blocks.section>
