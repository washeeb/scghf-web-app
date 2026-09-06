{{--
    The site footer.

    Three seeded menus — footer_primary, footer_support, footer_legal — plus the
    contact and registration details from the settings layer.

    ── The registration numbers are not decoration ─────────────────────────────

    A Ghanaian non-profit asking the public for money is expected to show who it
    is: the exact registered name, the registration number, and the body that
    registered it. A donor deciding whether to trust a payment form looks for
    exactly that, and its absence is what a scam site has in common with a real
    one that forgot.

    Every value comes from the CMS, and each is omitted when unfilled rather
    than rendering an empty label or a raw {{PLACEHOLDER}} — the settings layer
    treats an unfilled placeholder as absent for this reason.
--}}
@php
    $columns = [
        ['key' => 'footer_primary', 'heading' => setting('site.footer_primary_heading', __('Our work'))],
        ['key' => 'footer_support', 'heading' => setting('site.footer_support_heading', __('Support us'))],
    ];

    $legal = App\Models\Menu::renderable('footer_legal', auth()->check());

    $socials = collect([
        'facebook' => setting('social.facebook'),
        'instagram' => setting('social.instagram'),
        'x' => setting('social.x'),
        'linkedin' => setting('social.linkedin'),
        'youtube' => setting('social.youtube'),
        'tiktok' => setting('social.tiktok'),
    ])->filter();
@endphp

<footer class="mt-16 border-t border-[var(--border)] bg-[var(--surface)]">
    <div class="mx-auto max-w-6xl px-4 py-12">
        <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">

            {{-- Who we are, and how to reach us. --}}
            <div class="space-y-3">
                <p class="font-semibold text-[var(--text-primary)]">
                    {{ setting('general.legal_name', setting('general.short_name', config('app.name'))) }}
                </p>

                @if ($motto = setting('general.motto'))
                    <p class="text-sm text-[var(--text-muted)]">{{ $motto }}</p>
                @endif

                <address class="space-y-1 text-sm not-italic text-[var(--text-muted)]">
                    @if ($address = setting('contact.address'))
                        <p>{{ $address }}</p>
                    @endif

                    {{-- The Ghana Post digital address. Frequently the only way
                         to find a Ghanaian address, and useless if omitted. --}}
                    @if ($gps = setting('contact.gps_address'))
                        <p>{{ __('GPS') }}: {{ $gps }}</p>
                    @endif

                    @if ($phone = setting('contact.phone_primary'))
                        <p><a class="hover:underline" href="tel:{{ preg_replace('/\s+/', '', $phone) }}">{{ $phone }}</a></p>
                    @endif

                    @if ($email = setting('contact.email_general'))
                        <p><a class="hover:underline" href="mailto:{{ $email }}">{{ $email }}</a></p>
                    @endif
                </address>
            </div>

            {{-- The two content columns, from their menus. --}}
            @foreach ($columns as $column)
                @php $items = App\Models\Menu::renderable($column['key'], auth()->check()); @endphp

                @if ($items->isNotEmpty())
                    <nav aria-label="{{ $column['heading'] }}">
                        <h2 class="mb-3 text-sm font-semibold text-[var(--text-primary)]">{{ $column['heading'] }}</h2>
                        <ul class="space-y-1">
                            @foreach ($items as $item)
                                <li><x-site.menu-link :item="$item" /></li>
                            @endforeach
                        </ul>
                    </nav>
                @endif
            @endforeach

            {{-- Newsletter and social. --}}
            <div class="space-y-4">
                <h2 class="text-sm font-semibold text-[var(--text-primary)]">
                    {{ setting('site.footer_newsletter_heading', __('Stay in touch')) }}
                </h2>

                {{--
                    ⚠ This form has rendered NOTHING on every page of the site
                    since Phase 4. The guard below is correct and was doing its
                    job: `newsletter.subscribe` did not exist, so a form that
                    would have posted to a 404 was correctly not drawn. What was
                    missing was the route — and the whole double opt-in flow
                    behind it, which had been written in Phase 3 and called by
                    nobody.

                    The guard stays, because it is still the right answer if the
                    route is ever removed.
                --}}
                @if (Route::has('newsletter.subscribe'))
                    @if (session('status'))
                        {{-- The confirmation lands wherever the form was
                             submitted from, which is any page on the site.
                             `role="status"` announces it politely rather than
                             interrupting whatever is being read. --}}
                        <p role="status" class="text-sm text-[var(--success)]">{{ session('status') }}</p>
                    @endif

                    <form action="{{ route('newsletter.subscribe') }}" method="POST" class="space-y-2">
                        @csrf
                        <x-honeypot />

                        <div class="flex gap-2">
                            <label for="footer-email" class="sr-only">{{ __('Email address') }}</label>
                            <input
                                id="footer-email"
                                type="email"
                                name="email"
                                required
                                autocomplete="email"
                                placeholder="{{ __('you@example.com') }}"
                                @error('email') aria-invalid="true" aria-describedby="footer-email-error" @enderror
                                class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-sm text-[var(--text-primary)]"
                            >
                            <button
                                type="submit"
                                class="rounded-md bg-[var(--brand-primary)] px-3 py-2 text-sm font-semibold text-[var(--text-on-brand)]"
                            >{{ __('Join') }}</button>
                        </div>

                        @error('email')
                            <p id="footer-email-error" role="alert" class="text-sm text-[var(--danger)]">{{ $message }}</p>
                        @enderror

                        {{-- The consent sentence, from the settings layer, and
                             the same one snapshotted onto the subscriber's
                             record. Signing up IS the consent here — the
                             confirmation email is the second half of it. --}}
                        <p class="text-xs text-[var(--text-muted)]">
                            {{ setting('compliance.newsletter_consent_text') }}
                        </p>
                    </form>
                @endif

                @if ($socials->isNotEmpty())
                    <ul class="flex flex-wrap gap-3 text-sm">
                        @foreach ($socials as $network => $url)
                            <li>
                                <a
                                    href="{{ $url }}"
                                    target="_blank"
                                    rel="noopener noreferrer me"
                                    class="text-[var(--text-muted)] hover:text-[var(--brand-primary)]"
                                >{{ Str::title($network) }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- Legal strip. --}}
        <div class="mt-10 flex flex-col gap-4 border-t border-[var(--border)] pt-6 text-sm text-[var(--text-muted)] sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <p>
                    &copy; {{ now()->year }}
                    {{ setting('general.legal_name', setting('general.short_name', config('app.name'))) }}
                </p>

                @php
                    $registration = collect([
                        setting('general.registration_number')
                            ? __('Registration').' '.setting('general.registration_number')
                            : null,
                        setting('general.registering_authority'),
                        setting('general.tin') ? __('TIN').' '.setting('general.tin') : null,
                    ])->filter();
                @endphp

                @if ($registration->isNotEmpty())
                    <p>{{ $registration->implode(' · ') }}</p>
                @endif
            </div>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6">
                @if ($legal->isNotEmpty())
                    <nav aria-label="{{ __('Legal') }}">
                        <ul class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach ($legal as $item)
                                <li><x-site.menu-link :item="$item" /></li>
                            @endforeach
                        </ul>
                    </nav>
                @endif

                {{--
                    Back to top.

                    A plain in-page link to the skip-link's own target, not a
                    floating button and not a script. Pointing it at
                    `#main-content` rather than at the document top means it
                    MOVES FOCUS as well as scrolling — a JavaScript scroll
                    leaves a keyboard user's focus stranded at the bottom of the
                    page they just left, which is the usual way this control is
                    built and the usual way it is broken.
                --}}
                @if (setting('site.show_back_to_top', true))
                    <a
                        href="#main-content"
                        class="shrink-0 hover:text-[var(--brand-primary)] hover:underline"
                    >{{ __('Back to top') }}</a>
                @endif
            </div>
        </div>
    </div>
</footer>
