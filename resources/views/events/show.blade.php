{{--
    One event.

    ── Directions are a link, not an embedded map ──────────────────────────────

    An embedded map is a third-party script and a few hundred kilobytes on a
    3G connection, for a widget most people tap once to open the same maps app
    the link opens. The link goes to a maps search for the address, which
    works in whatever the phone has.

    ── The join link for an online event is NOT on this page ──────────────────

    It is sent to the people who register. Printing it here would make
    registration pointless.

    ── The registration form asks three separate consents ──────────────────────

    Holding the details (required), contact about this event (optional), the
    newsletter (optional, never pre-ticked) — and photography as a yes/no
    question, because "we never asked" must be distinguishable from "no".
--}}
@php
    $where = collect([$event->venue_name, $event->address, $event->area, $event->region])->filter();
    $mapsUrl = $where->isEmpty() ? null : 'https://www.google.com/maps/search/?api=1&query='.urlencode($where->implode(', ').', Ghana');
    $cancelled = $event->status === App\Models\Event::STATUS_CANCELLED;
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode(array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => $event->title,
        'description' => $event->summary,
        'startDate' => $event->starts_at->toIso8601String(),
        'endDate' => $event->ends_at?->toIso8601String(),
        'eventStatus' => $cancelled ? 'https://schema.org/EventCancelled' : ($event->status === App\Models\Event::STATUS_POSTPONED ? 'https://schema.org/EventPostponed' : 'https://schema.org/EventScheduled'),
        'eventAttendanceMode' => $event->is_online ? 'https://schema.org/OnlineEventAttendanceMode' : 'https://schema.org/OfflineEventAttendanceMode',
        'location' => $event->is_online
            ? ['@type' => 'VirtualLocation', 'url' => route('events.show', $event)]
            : array_filter(['@type' => 'Place', 'name' => $event->venue_name, 'address' => $where->implode(', ') ?: null]),
        'organizer' => ['@type' => 'Organization', 'name' => setting('general.legal_name', setting('general.short_name', config('app.name'))), 'url' => url('/')],
        'isAccessibleForFree' => true,
        'url' => route('events.show', $event),
    ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

<x-layouts.app :meta="$meta">
    <x-site.breadcrumbs :crumbs="$crumbs" />

    <article class="mx-auto max-w-6xl px-4 pb-16 pt-6">
        <div class="grid gap-10 lg:grid-cols-[2fr_1fr]">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-[var(--brand-primary)]">
                    <time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->format('l j F Y') }}</time>
                </p>

                <h1 class="mt-1 text-3xl font-bold tracking-tight text-[var(--text-primary)] sm:text-4xl">{{ $event->title }}</h1>

                @if ($cancelled)
                    <p role="status" class="mt-4 rounded-md border border-[var(--danger)] px-4 py-3 text-[var(--text-primary)]">
                        <strong>{{ __('This event has been cancelled.') }}</strong>
                        @if ($event->cancellation_reason) {{ $event->cancellation_reason }} @endif
                    </p>
                @elseif ($event->status === App\Models\Event::STATUS_POSTPONED)
                    <p role="status" class="mt-4 rounded-md border border-[var(--warning)] px-4 py-3 text-[var(--text-primary)]">
                        {{ __('This event has been postponed. A new date will be announced here.') }}
                    </p>
                @endif

                @if ($event->summary)
                    <p class="mt-3 text-lg text-[var(--text-secondary)]">{{ $event->summary }}</p>
                @endif

                @if ($event->featuredImage)
                    <x-media.image :media="$event->featuredImage" size="hero" :eager="true" class="mt-6 aspect-[3/2] w-full rounded-lg object-cover" />
                @endif

                @if ($event->description)
                    <div class="prose-scghf mt-8 space-y-4 text-[var(--text-primary)]">{!! $event->description !!}</div>
                @endif

                @if ($event->hasFinished() && ($event->outcomes || $event->attendance_count !== null || $event->gallery?->is_published))
                    <section class="mt-8" aria-labelledby="outcomes-heading">
                        <h2 id="outcomes-heading" class="text-lg font-semibold text-[var(--text-primary)]">{{ __('What happened') }}</h2>
                        @if ($event->attendance_count !== null)
                            <p class="mt-2 text-[var(--text-secondary)]">{{ trans_choice('{1}:count person came.|[2,*]:count people came.', $event->attendance_count, ['count' => number_format($event->attendance_count)]) }}</p>
                        @endif
                        @if ($event->outcomes)
                            <div class="prose-scghf mt-3 space-y-4 text-[var(--text-primary)]">{!! $event->outcomes !!}</div>
                        @endif
                        @if ($event->gallery?->is_published)
                            <ul class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3" aria-label="{{ __('Photographs') }}">
                                @foreach ($event->gallery->items()->with('media')->limit(6)->get() as $item)
                                    @if ($item->media)
                                        <li><x-media.image :media="$item->media" size="card" class="aspect-square w-full rounded-lg object-cover" /></li>
                                    @endif
                                @endforeach
                            </ul>
                            <a class="mt-3 inline-block font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('galleries.show', $event->gallery) }}">{{ __('All the photographs') }}</a>
                        @endif
                    </section>
                @endif

                @if ($event->accessibility_notes)
                    <section class="mt-8" aria-labelledby="access-heading">
                        <h2 id="access-heading" class="text-lg font-semibold text-[var(--text-primary)]">{{ __('Getting in and around') }}</h2>
                        <p class="mt-2 text-[var(--text-secondary)]">{{ $event->accessibility_notes }}</p>
                    </section>
                @endif

                @if ($event->cause && $event->cause->acceptsDonations())
                    <p class="mt-8 rounded-md border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-[var(--text-secondary)]">
                        {{ __('This event raises money for') }}
                        <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('causes.show', $event->cause) }}">{{ $event->cause->title }}</a>.
                        {{ __('Cannot come?') }}
                        <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('donate', ['cause' => $event->cause->slug]) }}">{{ __('You can still give.') }}</a>
                    </p>
                @endif
            </div>

            <aside class="space-y-6">
                <dl class="space-y-3 rounded-lg border border-[var(--border)] p-5 text-sm">
                    <div>
                        <dt class="font-semibold text-[var(--text-primary)]">{{ __('When') }}</dt>
                        <dd class="text-[var(--text-secondary)]">
                            {{ $event->starts_at->format('l j F Y, H:i') }}
                            @if ($event->ends_at)
                                – {{ $event->ends_at->isSameDay($event->starts_at) ? $event->ends_at->format('H:i') : $event->ends_at->format('l j F Y, H:i') }}
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="font-semibold text-[var(--text-primary)]">{{ __('Where') }}</dt>
                        <dd class="text-[var(--text-secondary)]">
                            @if ($event->is_online)
                                {{ __('Online — the link is sent to everybody who registers.') }}
                            @else
                                {{ $where->isEmpty() ? __('To be announced') : $where->implode(', ') }}
                                @if ($mapsUrl)
                                    <br><a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ $mapsUrl }}" rel="noopener" target="_blank">{{ __('Get directions') }}</a>
                                @endif
                            @endif
                        </dd>
                    </div>

                    @if ($event->registration_required && $event->capacity !== null && ! $cancelled)
                        <div>
                            <dt class="font-semibold text-[var(--text-primary)]">{{ __('Places') }}</dt>
                            <dd class="text-[var(--text-secondary)]">
                                {{ $event->isFull()
                                    ? __('Full — you can join the waiting list.')
                                    : trans_choice('{1}:count place left|[2,*]:count places left', $event->placesRemaining(), ['count' => $event->placesRemaining()]) }}
                            </dd>
                        </div>
                    @endif
                </dl>

                @if ($event->hasFinished())
                    <p class="rounded-lg border border-[var(--border)] p-5 text-[var(--text-secondary)]">{{ __('This event has taken place.') }}</p>
                @elseif ($event->registration_required && ! $cancelled)
                    <section id="register" class="rounded-lg border border-[var(--border)] p-5" aria-labelledby="register-heading">
                        <h2 id="register-heading" class="text-lg font-semibold text-[var(--text-primary)]">
                            {{ $event->isFull() ? __('Join the waiting list') : __('Register to come') }}
                        </h2>

                        @if ($rejection)
                            <p class="mt-2 text-[var(--text-secondary)]">{{ $rejection }}</p>
                        @else
                            @error('registration')
                                <p role="alert" class="mt-2 rounded-md border border-[var(--danger)] px-3 py-2 text-sm text-[var(--text-primary)]">{{ $message }}</p>
                            @enderror

                            <form method="POST" action="{{ route('events.register', $event) }}" class="mt-4 space-y-4">
                                @csrf
                                <x-honeypot />

                                <x-site.field name="name" :label="__('Your name')" required autocomplete="name" :value="auth()->user()?->name" />
                                <x-site.field name="email" type="email" :label="__('Email address')" required autocomplete="email" :value="auth()->user()?->email" :hint="__('Your confirmation goes here.')" />
                                <x-site.field name="phone" type="tel" :label="__('Phone number')" autocomplete="tel" :hint="__('Optional. In case we need to reach you on the day.')" />

                                <div>
                                    <label for="guests" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Bringing anybody?') }}</label>
                                    <input id="guests" type="number" name="guests" value="{{ old('guests', 0) }}" min="0" max="10" inputmode="numeric" class="w-24 rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)]">
                                    <p class="mt-1 text-xs text-[var(--text-muted)]">{{ __('How many guests, not counting you.') }}</p>
                                </div>

                                <x-site.field name="accessibility_needs" type="textarea" :rows="2" :label="__('Anything we should know to make it accessible for you?')" :hint="__('Optional. Seen only by the organisers.')" />
                                <x-site.field name="dietary_needs" type="textarea" :rows="2" :label="__('Dietary needs')" :hint="__('Optional, where food is provided.')" />

                                <fieldset>
                                    <legend class="text-sm font-medium text-[var(--text-primary)]">{{ __('We take photographs at our events. Are you happy to be in them?') }} <span class="text-[var(--brand-secondary)]" aria-hidden="true">*</span></legend>
                                    <div class="mt-2 flex gap-6">
                                        <label class="flex items-center gap-2 text-sm text-[var(--text-primary)]">
                                            <input type="radio" name="photography_consent" value="1" @checked(old('photography_consent') === '1') class="size-4 accent-[var(--brand-primary)]"> {{ __('Yes') }}
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-[var(--text-primary)]">
                                            <input type="radio" name="photography_consent" value="0" @checked(old('photography_consent') === '0') class="size-4 accent-[var(--brand-primary)]"> {{ __('No') }}
                                        </label>
                                    </div>
                                    @error('photography_consent')
                                        <p role="alert" class="mt-1 text-sm text-[var(--danger)]">{{ $message }}</p>
                                    @enderror
                                </fieldset>

                                <x-site.checkbox name="consent" required :label="setting('compliance.event_consent_text', __('I agree that my details may be held in order to run this event.'))" />
                                <x-site.checkbox name="contact_consent" :label="__('You may contact me about this event — a reminder, or a change of plan.')" />
                                <x-site.checkbox name="newsletter_consent" :label="setting('compliance.newsletter_consent_text', __('I would like to receive email updates, and I can unsubscribe at any time.'))" />

                                <button type="submit" class="w-full rounded-md bg-[var(--brand-primary)] px-6 py-3 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">
                                    {{ $event->isFull() ? __('Join the waiting list') : __('Register') }}
                                </button>
                            </form>
                        @endif
                    </section>
                @elseif (! $cancelled)
                    <p class="rounded-lg border border-[var(--border)] p-5 text-[var(--text-secondary)]">{{ __('No need to register — just come along.') }}</p>
                @endif
            </aside>
        </div>
    </article>
</x-layouts.app>
