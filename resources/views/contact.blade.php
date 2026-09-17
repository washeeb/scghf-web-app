{{--
    Contact.

    ── The form works with no JavaScript ───────────────────────────────────────

    A plain POST to a named route, server-validated, with errors rendered on the
    page. Nothing here waits for a script — which matters most for exactly the
    visitor most likely to need the form: somebody on a slow connection who has
    been trying to reach the foundation another way.

    ── Old input is repopulated ────────────────────────────────────────────────

    A rejected form that comes back empty is one somebody abandons. `old()` on
    every field, including the department and the consent tick.

    ── The honeypot is a package, not a hand-rolled hidden field ───────────────

    `<x-honeypot />` from spatie/laravel-honeypot: a decoy field plus an
    encrypted timestamp, so a submission that arrives in under a second or fills
    a field no human can see is rejected. The middleware on the route is the
    other half; neither works alone.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Contact us')"
    :lead="__('We read everything that arrives here.')"
>
    <div class="grid gap-12 lg:grid-cols-[2fr_1fr]">

        <div class="max-w-2xl">
            @if (session('status'))
                {{-- `role="status"` and not `alert`: this is the outcome of
                     something the visitor did, announced politely, rather than
                     an interruption. --}}
                <div
                    role="status"
                    class="mb-6 rounded-lg border border-[var(--success)] bg-[var(--surface)] p-4 text-sm text-[var(--text-primary)]"
                >
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('contact.store') }}" class="space-y-5">
                @csrf
                <x-honeypot />

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-site.field name="name" :label="__('Your name')" required autocomplete="name" />
                    <x-site.field name="email" type="email" :label="__('Email address')" required autocomplete="email" />
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-site.field
                        name="phone"
                        type="tel"
                        :label="__('Phone number')"
                        :hint="__('Optional. A Ghanaian number, e.g. 024 123 4567.')"
                        autocomplete="tel"
                    />

                    @if ($departments->isNotEmpty())
                        <div>
                            <label for="contact_department_id" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">
                                {{ __('What is it about?') }}
                            </label>

                            <select
                                id="contact_department_id"
                                name="contact_department_id"
                                class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)]"
                                @error('contact_department_id') aria-invalid="true" aria-describedby="contact_department_id-error" @enderror
                            >
                                <option value="">{{ __('General enquiry') }}</option>

                                @foreach ($departments as $department)
                                    <option
                                        value="{{ $department->getKey() }}"
                                        @selected(old('contact_department_id') == $department->getKey())
                                    >{{ $department->name }}</option>
                                @endforeach
                            </select>

                            @error('contact_department_id')
                                <p id="contact_department_id-error" role="alert" class="mt-1 text-sm text-[var(--danger)]">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    @endif
                </div>

                <x-site.field name="subject" :label="__('Subject')" />

                <x-site.field
                    name="message"
                    type="textarea"
                    :label="__('Your message')"
                    required
                    :hint="__('Please tell us enough that we can give you a useful answer.')"
                />

                <x-site.checkbox name="consent" :label="__('I agree that my details may be stored so that you can reply to me.')" />

                <button
                    type="submit"
                    class="rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                >{{ __('Send message') }}</button>
            </form>
        </div>

        {{-- Every value from the settings layer. Nothing here is typed into the
             template, and anything the foundation has not filled in is omitted
             rather than rendering an empty label. --}}
        <aside class="space-y-6 text-sm">
            @if ($offices->isNotEmpty())
                {{-- The offices table, once it has a row, replaces the single
                     address from the settings. Each office: where, when it is
                     open, how to reach it, how to get there. --}}
                <div class="space-y-6">
                    @foreach ($offices as $office)
                        <section aria-labelledby="office-{{ $office->id }}" class="rounded-lg border border-[var(--border)] p-4">
                            <h2 id="office-{{ $office->id }}" class="font-semibold text-[var(--text-primary)]">
                                {{ $office->name }}
                                @if ($office->is_primary && $offices->count() > 1)
                                    <span class="ml-1 rounded-full border border-[var(--border)] px-2 py-0.5 text-xs font-normal text-[var(--text-muted)]">{{ __('Main office') }}</span>
                                @endif
                            </h2>

                            <address class="mt-2 space-y-1 not-italic text-[var(--text-secondary)]">
                                @if ($office->address)<p>{{ $office->address }}</p>@endif
                                @if ($office->city || $office->region)<p>{{ collect([$office->city, $office->region])->filter()->implode(', ') }}</p>@endif
                                @if ($office->gps_address)<p>{{ __('Ghana Post GPS') }}: {{ $office->gps_address }}</p>@endif
                                @if ($office->notes)<p class="text-[var(--text-muted)]">{{ $office->notes }}</p>@endif
                            </address>

                            @if ($rows = $office->hoursRows())
                                <h3 class="mt-3 text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">{{ __('Hours') }}</h3>
                                <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 text-[var(--text-secondary)]">
                                    @foreach ($rows as $row)
                                        <dt>{{ $row['day'] }}</dt>
                                        <dd>{{ $row['hours'] }}</dd>
                                    @endforeach
                                </dl>
                            @endif

                            <ul role="list" class="mt-3 space-y-1 text-[var(--text-secondary)]">
                                @if ($office->phone)
                                    <li><a class="hover:underline" href="tel:{{ preg_replace('/\s+/', '', $office->phone) }}">{{ $office->phone }}</a></li>
                                @endif
                                @if ($wa = $office->whatsappUrl())
                                    <li><a class="hover:underline" href="{{ $wa }}" target="_blank" rel="noopener noreferrer">{{ __('WhatsApp') }}</a></li>
                                @endif
                                @if ($office->email)
                                    <li><a class="hover:underline" href="mailto:{{ $office->email }}">{{ $office->email }}</a></li>
                                @endif
                                @if ($directions = $office->directionsUrl())
                                    <li><a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ $directions }}" target="_blank" rel="noopener noreferrer">{{ __('Get directions') }}</a></li>
                                @endif
                            </ul>
                        </section>
                    @endforeach
                </div>
            @else
            <div>
                <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Where we are') }}</h2>

                <address class="mt-2 space-y-1 not-italic text-[var(--text-secondary)]">
                    @if ($address = setting('contact.address'))
                        <p>{{ $address }}</p>
                    @endif

                    @if ($city = setting('contact.city'))
                        <p>{{ collect([$city, setting('contact.district'), setting('contact.region')])->filter()->implode(', ') }}</p>
                    @endif

                    {{-- Frequently the only address that finds a Ghanaian
                         building, and useless if omitted. --}}
                    @if ($gps = setting('contact.gps_address'))
                        <p>{{ __('Ghana Post GPS') }}: {{ $gps }}</p>
                    @endif

                    @if ($box = setting('contact.postal_address'))
                        <p>{{ $box }}</p>
                    @endif
                </address>
            </div>

            @if ($hours = setting('contact.office_hours'))
                <div>
                    <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Office hours') }}</h2>
                    <p class="mt-2 text-[var(--text-secondary)]">{{ $hours }}</p>
                </div>
            @endif

            <div>
                <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Talk to us') }}</h2>

                <ul role="list" class="mt-2 space-y-1 text-[var(--text-secondary)]">
                    @if ($phone = setting('contact.phone_primary'))
                        <li>
                            <a class="hover:underline" href="tel:{{ preg_replace('/\s+/', '', $phone) }}">{{ $phone }}</a>
                        </li>
                    @endif

                    @if ($whatsapp = setting('contact.whatsapp'))
                        <li>
                            {{-- wa.me needs the international form with no
                                 punctuation. A Ghanaian number written 024...
                                 is 23324... to WhatsApp, and a link that opens
                                 an empty chat is the usual result of pasting
                                 the local form. --}}
                            @php
                                $digits = preg_replace('/\D+/', '', $whatsapp);
                                $international = str_starts_with($digits, '0')
                                    ? '233'.substr($digits, 1)
                                    : $digits;
                            @endphp

                            <a
                                class="hover:underline"
                                href="https://wa.me/{{ $international }}"
                                target="_blank"
                                rel="noopener noreferrer"
                            >{{ __('WhatsApp us') }}</a>
                        </li>
                    @endif

                    @if ($email = setting('contact.email_general'))
                        <li><a class="hover:underline" href="mailto:{{ $email }}">{{ $email }}</a></li>
                    @endif
                </ul>
            </div>
            @endif

            @if ($departments->isNotEmpty())
                <div>
                    <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Departments') }}</h2>

                    <ul role="list" class="mt-2 space-y-2 text-[var(--text-secondary)]">
                        @foreach ($departments as $department)
                            <li>
                                <span class="font-medium text-[var(--text-primary)]">{{ $department->name }}</span>

                                @if ($department->sla_hours)
                                    <span class="block text-xs text-[var(--text-muted)]">
                                        {{ __('We aim to reply within :hours hours.', ['hours' => $department->sla_hours]) }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </aside>
    </div>
</x-site.page-shell>
