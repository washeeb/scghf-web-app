{{--
    The donation form.

    ── It works with no JavaScript ─────────────────────────────────────────────

    A plain POST, server-validated, errors rendered on the page. On a low-end
    Android phone over a metered connection — the real usage context here — a
    donation form that waits for a script is a donation form that loses the
    gift. The amount presets are radio buttons, not chips a script wires up.

    ── The presets and the custom box are one field ────────────────────────────

    Both write to `amount`. A separate "other amount" input that the presets do
    not clear is how somebody selects GH₵ 100, types 250 underneath, and gives
    whichever one the server happened to read.

    ── Covering the fee is offered, not assumed ────────────────────────────────

    Ticked by default because most donors say yes and it is the difference
    between the appeal receiving the whole gift or the gateway taking its cut —
    but it is a checkbox they can see and clear, not a silent addition to the
    total. `DonationService` GROSSES UP rather than adding the fee, because the
    fee is charged on the larger amount and `amount + fee(amount)` always leaves
    the foundation a little short.
--}}
@php
    $currency = setting('donations.currency_symbol', 'GH₵');
    $allowFeeCover = (bool) setting('donations.allow_fee_cover', true);
    $allowAnonymous = (bool) setting('donations.allow_anonymous', true);
    $allowTribute = (bool) setting('donations.allow_tribute', true);
    $allowRecurring = $cause === null ? true : (bool) $cause->allow_recurring;
    /*
     * `old()` first, then the amount a giving-level link carried. A donor who
     * clicked "Give GH₵ 50 — a school kit" and then had the form rejected for
     * an unrelated field must not have their choice quietly reset.
     */
    $selected = old('amount_other') ?: old('amount', request()->string('amount_other')->toString() ?: (request()->string('amount')->toString() ?: null));

    $allowMomoDirect = (bool) setting('donations.allow_mobile_money_direct', true);
    $allowPublicMessage = (bool) setting('donations.allow_public_message', true);
    // As with the amount: a link or the donation widget may carry the frequency.
    $requestedFrequency = request()->string('frequency')->toString();
    $frequency = old('frequency', in_array($requestedFrequency, ['once', 'weekly', 'monthly', 'quarterly', 'annually'], true) ? $requestedFrequency : 'once');
    $payWith = old('pay_with', 'gateway');
@endphp

<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="$cause ? __('Give to :appeal', ['appeal' => $cause->title]) : __('Make a donation')"
    :lead="$cause?->summary"
>
    <div class="grid gap-12 lg:grid-cols-[2fr_1fr]">
        <form method="POST" action="{{ route('donate.store') }}" class="max-w-2xl space-y-8">
            @csrf
            <x-honeypot />

            @if ($cause)
                <input type="hidden" name="cause" value="{{ $cause->slug }}">
            @endif

            {{-- Where the donor came from — a WhatsApp broadcast, a radio
                 advert's short link — carried from the query string so a
                 finance report can say which appeal channel actually raised
                 money. Never shown; never trusted for anything else. --}}
            @foreach ($attribution as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach

            <fieldset>
                <legend class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ __('How much would you like to give?') }}
                </legend>

                @php
                    /*
                     * The appeal's own levels take precedence over the site
                     * presets. "GH₵ 50 provides a school kit for one child"
                     * answers the question a hesitant donor is actually asking
                     * — not "how much should I give?" but "what does my money
                     * do?" — and a generic row of round numbers does not.
                     */
                    $levels = $cause?->givingLevels() ?? collect();
                @endphp

                @if ($levels->isNotEmpty())
                    <ul role="list" class="mt-4 space-y-3">
                        @foreach ($levels as $level)
                            @php $value = $level['amount']->toMajorString(); @endphp

                            <li>
                                <label class="flex cursor-pointer gap-3 rounded-md border border-[var(--border)] p-4 has-[:checked]:border-[var(--brand-primary)]">
                                    <input
                                        type="radio"
                                        name="amount"
                                        value="{{ $value }}"
                                        @checked($selected === $value)
                                        class="mt-1 size-4 shrink-0 accent-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                                    >

                                    <span>
                                        <span class="block font-semibold text-[var(--text-primary)]">
                                            {{ $level['amount']->format() }} — {{ $level['label'] }}
                                        </span>

                                        @if ($level['description'])
                                            <span class="mt-1 block text-sm text-[var(--text-secondary)]">
                                                {{ $level['description'] }}
                                            </span>
                                        @endif
                                    </span>
                                </label>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($presets->isNotEmpty())
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($presets as $preset)
                            @php $value = $preset->toMajorString(); @endphp

                            <label class="cursor-pointer">
                                {{-- A real radio, visually hidden rather than
                                     removed: `display:none` takes it out of the
                                     tab order and off a screen reader, which is
                                     how a preset row becomes unusable by
                                     keyboard. --}}
                                <input
                                    type="radio"
                                    name="amount"
                                    value="{{ $value }}"
                                    @checked($selected === $value)
                                    class="peer sr-only"
                                >

                                <span class="block rounded-md border border-[var(--border)] px-4 py-3 text-center font-semibold text-[var(--text-primary)] peer-checked:border-[var(--brand-primary)] peer-checked:bg-[var(--brand-primary)] peer-checked:text-[var(--text-on-brand)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--focus-ring)]">
                                    {{ $preset->format() }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif

                <div class="mt-4">
                    <label for="amount-other" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">
                        {{ ($levels->isNotEmpty() || $presets->isNotEmpty()) ? __('Or another amount') : __('Amount') }}
                    </label>

                    <div class="flex items-center gap-2">
                        <span class="text-[var(--text-muted)]" aria-hidden="true">{{ $currency }}</span>

                        <input
                            id="amount-other"
                            type="number"
                            name="amount_other"
                            step="0.01"
                            min="0.01"
                            inputmode="decimal"
                            value="{{ ($presets->contains(fn ($p) => $p->toMajorString() === $selected)
                                || $levels->contains(fn ($l) => $l['amount']->toMajorString() === $selected))
                                ? '' : $selected }}"
                            @error('amount') aria-invalid="true" aria-describedby="amount-error" @enderror
                            class="w-40 rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
                        >
                    </div>

                    @error('amount')
                        <p id="amount-error" role="alert" class="mt-1 text-sm text-[var(--danger)]">{{ $message }}</p>
                    @enderror
                </div>
            </fieldset>

            @unless ($cause)
                <div>
                    <label for="cause" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">
                        {{ __('Where should it go?') }}
                    </label>

                    <select
                        id="cause"
                        name="cause"
                        class="w-full max-w-md rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)]"
                    >
                        {{-- The General Fund is the default and is never a dead
                             end: `Cause::generalFund()` throws rather than
                             returning null, because a gift with no destination
                             is money the foundation has taken and cannot
                             account for. --}}
                        <option value="">{{ __('Wherever it is needed most') }}</option>

                        @foreach ($causes as $option)
                            <option value="{{ $option->slug }}" @selected(old('cause') === $option->slug)>
                                {{ $option->title }}
                            </option>
                        @endforeach
                    </select>

                    @error('cause')
                        <p role="alert" class="mt-1 text-sm text-[var(--danger)]">{{ $message }}</p>
                    @enderror
                </div>
            @endunless

            <fieldset class="space-y-5">
                <legend class="text-lg font-semibold text-[var(--text-primary)]">{{ __('About you') }}</legend>

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-site.field name="donor_name" :label="__('Your name')" required autocomplete="name" />
                    <x-site.field name="donor_email" type="email" :label="__('Email address')" required autocomplete="email"
                        :hint="__('Your receipt goes here.')" />
                </div>

                <x-site.field
                    name="donor_phone"
                    type="tel"
                    :label="__('Phone number')"
                    :hint="__('Optional. Only used if there is a problem with your gift.')"
                    autocomplete="tel"
                />
            </fieldset>

            @if ($allowRecurring)
                <fieldset>
                    <legend class="text-lg font-semibold text-[var(--text-primary)]">{{ __('How often?') }}</legend>

                    <p class="mt-1 text-sm text-[var(--text-secondary)]">
                        {{ __('A regular gift is set up after this first payment goes through. You can stop it at any time from your account or by replying to any receipt.') }}
                    </p>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ([
                            'once' => __('Just once'),
                            'weekly' => __('Every week'),
                            'monthly' => __('Every month'),
                            'quarterly' => __('Every three months'),
                            'annually' => __('Every year'),
                        ] as $value => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="frequency" value="{{ $value }}" @checked($frequency === $value) class="peer sr-only">
                                <span class="block rounded-md border border-[var(--border)] px-4 py-2 text-sm font-medium text-[var(--text-primary)] peer-checked:border-[var(--brand-primary)] peer-checked:bg-[var(--brand-primary)] peer-checked:text-[var(--text-on-brand)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--focus-ring)]">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endif

            <fieldset class="space-y-3">
                <legend class="text-lg font-semibold text-[var(--text-primary)]">{{ __('A few choices') }}</legend>

                @if ($allowFeeCover)
                    <x-site.checkbox
                        name="cover_fee"
                        :checked="true"
                        :label="__('Add a little to cover the transaction fee, so the full amount reaches the work')"
                    />
                @endif


                @if ($allowAnonymous)
                    <x-site.checkbox
                        name="is_anonymous"
                        :label="__('Give anonymously')"
                        :hint="__('Your name will not appear on the appeal page. We still need it for your receipt.')"
                    />
                @endif
            </fieldset>

            @if ($allowPublicMessage)
                <x-site.field
                    name="public_message"
                    type="textarea"
                    :rows="2"
                    :label="__('A message for the donor wall')"
                    :hint="__('Optional. Shown beside your name on the appeal page — or just the message, if you give anonymously.')"
                />
            @endif

            <details class="rounded-lg border border-[var(--border)] p-4">
                <summary class="cursor-pointer font-medium text-[var(--text-primary)]">{{ __('Add a postal address') }}</summary>
                <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ __('Optional. Only if you would like it on your receipt.') }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <x-site.field name="donor_address" :label="__('Address')" autocomplete="street-address" />
                    <x-site.field name="donor_city" :label="__('Town or city')" autocomplete="address-level2" />
                </div>
            </details>

            @if ($allowTribute)
                {{-- `<details>`, so the form is short for the 95% who are not
                     giving in tribute and complete for the ones who are. The
                     emotional centre of a memorial foundation, and not
                     something to bury behind a second page. --}}
                <details class="rounded-lg border border-[var(--border)] p-4">
                    <summary class="cursor-pointer font-medium text-[var(--text-primary)]">
                        {{ __('Give in memory of, or in honour of, somebody') }}
                    </summary>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="tribute_type" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">
                                {{ __('This gift is') }}
                            </label>

                            <select id="tribute_type" name="tribute_type"
                                class="w-full max-w-xs rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)]">
                                <option value="">{{ __('Not a tribute') }}</option>
                                <option value="memory" @selected(old('tribute_type') === 'memory')>{{ __('In memory of') }}</option>
                                <option value="honour" @selected(old('tribute_type') === 'honour')>{{ __('In honour of') }}</option>
                            </select>
                        </div>

                        <x-site.field name="tribute_name" :label="__('Their name')" />
                        <x-site.field name="tribute_message" type="textarea" :rows="3" :label="__('A message')" />
                        <x-site.field name="tribute_notify_email" type="email" :label="__('Tell somebody about this gift')"
                            :hint="__('Optional. We will let them know a gift was made — never how much.')" />
                    </div>
                </details>
            @endif

            <fieldset class="space-y-3">
                <legend class="sr-only">{{ __('Permissions') }}</legend>

                <x-site.checkbox
                    name="consent"
                    :label="setting('compliance.donation_consent_text')"
                />

                {{-- Separate, optional, and it does NOT block the gift. A
                     donation form that refuses money unless somebody joins a
                     mailing list is a donation form that loses money. --}}
                <x-site.checkbox name="consent_email" :label="__('Send me occasional updates about this work by email')" />
                <x-site.checkbox name="consent_sms" :label="__('Send me occasional updates by SMS')" />
            </fieldset>

            @if ($allowMomoDirect)
                {{--
                    How to pay. The hosted page takes cards and Mobile Money and
                    is the default; the direct charge sends the prompt straight
                    to the wallet without leaving this site, which for a donor
                    on a slow connection is one page fewer to load — and the
                    page that fails to load is usually the payment provider's.
                --}}
                <fieldset class="space-y-3">
                    <legend class="text-lg font-semibold text-[var(--text-primary)]">{{ __('How would you like to pay?') }}</legend>

                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-[var(--border)] p-4 has-[:checked]:border-[var(--brand-primary)]">
                        <input type="radio" name="pay_with" value="gateway" @checked($payWith === 'gateway') class="mt-1 size-4 accent-[var(--brand-primary)]">
                        <span>
                            <span class="block font-semibold text-[var(--text-primary)]">{{ __('Card or Mobile Money, on the payment page') }}</span>
                            <span class="block text-sm text-[var(--text-secondary)]">{{ __('You will be taken to our payment provider. We never see or store your details.') }}</span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-[var(--border)] p-4 has-[:checked]:border-[var(--brand-primary)]">
                        <input type="radio" name="pay_with" value="momo" @checked($payWith === 'momo') class="mt-1 size-4 accent-[var(--brand-primary)]">
                        <span>
                            <span class="block font-semibold text-[var(--text-primary)]">{{ __('Mobile Money prompt to my phone') }}</span>
                            <span class="block text-sm text-[var(--text-secondary)]">{{ __('Stay here; approve the payment on your phone.') }}</span>
                        </span>
                    </label>

                    <div class="grid gap-4 rounded-md border border-[var(--border)] p-4 sm:grid-cols-2">
                        <div>
                            <label for="momo_provider" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Network') }}</label>
                            <select id="momo_provider" name="momo_provider"
                                @error('momo_provider') aria-invalid="true" aria-describedby="momo_provider-error" @enderror
                                class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)]">
                                <option value="">{{ __('Choose') }}</option>
                                @foreach ($momoProviders as $code => $label)
                                    <option value="{{ $code }}" @selected(old('momo_provider') === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('momo_provider')
                                <p id="momo_provider-error" role="alert" class="mt-1 text-sm text-[var(--brand-secondary)]">{{ $message }}</p>
                            @enderror
                        </div>

                        <x-site.field name="momo_phone" type="tel" :label="__('Wallet number')" autocomplete="tel" :hint="__('The number the prompt goes to. For the prompt only.')" />
                    </div>
                </fieldset>
            @else
                <input type="hidden" name="pay_with" value="gateway">
            @endif

            {{-- The summary the brief asks for: "GH₵ X to <cause>, <frequency>".
                 Filled by a few lines of script from the fields above, and
                 hidden entirely without one — a summary that could be wrong is
                 worse than none, and the gateway shows the amount regardless. --}}
            <p id="donation-summary" hidden class="rounded-md bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-primary)]" aria-live="polite"></p>

            <button
                type="submit"
                class="w-full rounded-md bg-[var(--brand-secondary)] px-6 py-3 text-lg font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)] sm:w-auto"
            >{{ __('Continue to payment') }}</button>

            <p class="text-xs text-[var(--text-muted)]">
                {{ __('Payments are taken by Paystack. We never see or store your card or wallet details.') }}
            </p>
        </form>

        @push('scripts')
            <script>
            (function () {
                var form = document.querySelector('form[action="{{ route('donate.store') }}"]');
                var out = document.getElementById('donation-summary');
                if (!form || !out) return;
                var causeName = {!! json_encode($cause?->title ?? null) !!};
                var freq = {!! json_encode([
                    'once' => __('one gift'),
                    'weekly' => __('every week'),
                    'monthly' => __('every month'),
                    'quarterly' => __('every three months'),
                    'annually' => __('every year'),
                ]) !!};
                var general = {!! json_encode(__('wherever it is needed most')) !!};
                function update() {
                    var amount = null;
                    form.querySelectorAll('input[name="amount"], input[name="amount_other"]').forEach(function (el) {
                        if ((el.type === 'radio' && el.checked) || (el.type !== 'radio' && el.value)) amount = el.value;
                    });
                    if (!amount || isNaN(parseFloat(amount))) { out.hidden = true; return; }
                    var f = form.querySelector('input[name="frequency"]:checked');
                    var causeSelect = form.querySelector('select[name="cause"]');
                    var cause = causeName || (causeSelect && causeSelect.value ? causeSelect.options[causeSelect.selectedIndex].text : general);
                    out.textContent = 'GH₵ ' + parseFloat(amount).toLocaleString('en-GH', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' → ' + cause + ', ' + (f ? freq[f.value] : freq.once) + '.';
                    out.hidden = false;
                }
                form.addEventListener('input', update);
                form.addEventListener('change', update);
                update();
            })();
            </script>
        @endpush

        <aside class="space-y-8">
            @if ($cause)
                <div class="rounded-lg border border-[var(--border)] p-5">
                    <x-site.progress :cause="$cause" />
                </div>
            @endif

            <div class="rounded-lg border border-[var(--border)] p-5 text-sm">
                <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Rather not use a card?') }}</h2>

                <p class="mt-2 text-[var(--text-secondary)]">
                    {{ __('Mobile Money and bank transfer work just as well, and cost us less per gift.') }}
                </p>

                <a class="mt-3 inline-block font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('give') }}">
                    {{ __('See the details') }}
                </a>
            </div>

            @if ($registration = setting('general.registration_number'))
                {{-- A donor deciding whether to trust a payment form looks for
                     exactly this, and its absence is what a scam site has in
                     common with a real one that forgot. --}}
                <p class="text-xs text-[var(--text-muted)]">
                    {{ setting('general.legal_name') }} · {{ __('Registration') }} {{ $registration }}
                </p>
            @endif
        </aside>
    </div>
</x-site.page-shell>
