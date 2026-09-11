{{--
    One role, and the application form — or the general application when
    `$role` is null.

    ── The checks are named before the form, not after ─────────────────────────

    An applicant for a role with contact with children is told, before they
    type anything, that a police clearance, two references and an interview
    come first. Somebody who would rather not go through that should find out
    here, not from an email three weeks later.

    ── Data that is not needed is not collected ────────────────────────────────

    Date of birth is asked only for a role that needs a police clearance,
    which is applied for against it. Convictions are free text, because a
    yes/no invites a no and the useful information is the circumstances.
--}}
@php
    $contact = $role?->involves_vulnerable_contact ?? true;
    $checks = $role?->requiredCheckLabels() ?? array_column((array) config('compliance.safeguarding.required_checks', []), 'label');
@endphp

<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="$role?->title ?? __('Apply to volunteer')"
    :lead="$role?->summary ?? setting('volunteering.intro', __('Give your time.'))"
>
    <div class="grid gap-12 lg:grid-cols-[1fr_1fr]">
        <div>
            @if ($role)
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="font-semibold text-[var(--text-primary)]">{{ __('Where') }}</dt>
                        <dd class="text-[var(--text-secondary)]">
                            {{ collect([
                                match ($role->placement_type) {
                                    App\Models\VolunteerOpportunity::PLACEMENT_OFFICE => __('In the office'),
                                    App\Models\VolunteerOpportunity::PLACEMENT_EVENTS => __('At events'),
                                    App\Models\VolunteerOpportunity::PLACEMENT_REMOTE => __('Remote'),
                                    default => __('In the field'),
                                },
                                $role->location,
                                $role->region,
                            ])->filter()->implode(', ') }}
                        </dd>
                    </div>
                    @if ($role->time_commitment)
                        <div>
                            <dt class="font-semibold text-[var(--text-primary)]">{{ __('Time') }}</dt>
                            <dd class="text-[var(--text-secondary)]">{{ $role->time_commitment }}</dd>
                        </div>
                    @endif
                    @if ($role->starts_on)
                        <div>
                            <dt class="font-semibold text-[var(--text-primary)]">{{ __('Starts') }}</dt>
                            <dd class="text-[var(--text-secondary)]">{{ $role->starts_on->format('j F Y') }}</dd>
                        </div>
                    @endif
                    @if ($role->closes_on)
                        <div>
                            <dt class="font-semibold text-[var(--text-primary)]">{{ __('Apply by') }}</dt>
                            <dd class="text-[var(--text-secondary)]">{{ $role->closes_on->format('j F Y') }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($role->description)
                    <div class="prose-scghf mt-8 space-y-4 text-[var(--text-primary)]">{!! $role->description !!}</div>
                @endif

                @if ($role->requirements)
                    <h2 class="mt-8 text-lg font-semibold text-[var(--text-primary)]">{{ __('What we are looking for') }}</h2>
                    <div class="prose-scghf mt-2 space-y-4 text-[var(--text-primary)]">{!! $role->requirements !!}</div>
                @endif
            @else
                <p class="text-[var(--text-secondary)]">{{ __('Tell us about yourself and how you would like to help, and we will be in touch about where you could fit.') }}</p>
            @endif

            <section class="mt-8 rounded-lg border border-[var(--border)] bg-[var(--surface)] p-5" aria-labelledby="checks-heading">
                <h2 id="checks-heading" class="font-semibold text-[var(--text-primary)]">{{ __('Before anybody starts') }}</h2>
                <p class="mt-2 text-sm text-[var(--text-secondary)]">
                    {{ $contact
                        ? __('This role involves contact with children or vulnerable adults, so every volunteer completes these checks first:')
                        : __('Every volunteer completes this first:') }}
                </p>
                <ul role="list" class="mt-2 list-disc space-y-1 pl-5 text-sm text-[var(--text-secondary)]">
                    @foreach ($checks as $check)
                        <li>{{ $check }}</li>
                    @endforeach
                </ul>
                <p class="mt-3 text-sm text-[var(--text-secondary)]">
                    <x-site.policy-link slug="safeguarding" :label="__('Read our safeguarding policy')" />
                </p>
            </section>
        </div>

        <div>
            @if (! $open)
                <p class="rounded-lg border border-[var(--border)] p-5 text-[var(--text-secondary)]">
                    {{ __('Applications for this role have closed.') }}
                    <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('volunteer.general') }}">{{ __('You can still apply generally.') }}</a>
                </p>
            @else
                <form method="POST" action="{{ $role ? route('volunteer.apply', $role) : route('volunteer.apply.general') }}" class="space-y-6">
                    @csrf
                    <x-honeypot />

                    <h2 class="text-lg font-semibold text-[var(--text-primary)]">{{ __('Apply') }}</h2>

                    @error('application')
                        <p role="alert" class="rounded-md border border-[var(--danger)] px-3 py-2 text-sm text-[var(--text-primary)]">{{ $message }}</p>
                    @enderror

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-site.field name="full_name" :label="__('Full name')" required autocomplete="name" :value="auth()->user()?->name" />
                        <x-site.field name="email" type="email" :label="__('Email address')" required autocomplete="email" :value="auth()->user()?->email" />
                        <x-site.field name="phone" type="tel" :label="__('Phone number')" required autocomplete="tel" :hint="__('024 123 4567 or +233 24 123 4567.')" />

                        <div>
                            <label for="region" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Region') }}</label>
                            <select id="region" name="region" autocomplete="address-level1" class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)]">
                                <option value="">{{ __('Choose') }}</option>
                                @foreach (App\Models\ShippingZone::REGIONS as $region)
                                    <option value="{{ $region }}" @selected(old('region') === $region)>{{ $region }}</option>
                                @endforeach
                            </select>
                            @error('region')<p role="alert" class="mt-1 text-sm text-[var(--danger)]">{{ $message }}</p>@enderror
                        </div>

                        <x-site.field name="occupation" :label="__('Occupation')" autocomplete="organization-title" />

                        @if ($contact)
                            <x-site.field name="date_of_birth" type="date" :label="__('Date of birth')" required autocomplete="bday"
                                :hint="__('Needed for the police clearance, which is applied for against it. Volunteers must be 18 or over.')" />
                        @endif
                    </div>

                    <x-site.field name="motivation" type="textarea" :rows="4" :label="__('Why would you like to volunteer with us?')" required />
                    <x-site.field name="experience" type="textarea" :rows="3" :label="__('Relevant experience')" :hint="__('Optional. Work, church, community — anything that bears on the role.')" />
                    <x-site.field name="availability" :label="__('When are you available?')" :hint="__('"Weekday evenings", "Saturdays", "school holidays".')" />

                    <fieldset class="space-y-4">
                        <legend class="text-sm font-medium text-[var(--text-primary)]">{{ __('Next of kin') }} <span class="font-normal text-[var(--text-muted)]">({{ __('optional — for field roles') }})</span></legend>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-site.field name="next_of_kin_name" :label="__('Name')" />
                            <x-site.field name="next_of_kin_phone" type="tel" :label="__('Phone')" />
                        </div>
                    </fieldset>

                    @if ($contact)
                        <x-site.field name="disclosed_convictions" type="textarea" :rows="3"
                            :label="__('Is there anything in your past we should know about?')"
                            :hint="__('Any conviction, caution or investigation that could be relevant to working with children or vulnerable adults. A disclosure is not automatically a refusal — it is weighed. Leave empty if there is nothing.')" />
                    @endif

                    <x-site.checkbox
                        name="declaration"
                        required
                        :label="setting('compliance.volunteer_declaration_text', __('I have read the safeguarding policy, I have disclosed any conviction, caution or investigation that could be relevant to working with children or vulnerable adults, and the information I have given is true.'))"
                    />

                    <button type="submit" class="rounded-md bg-[var(--brand-primary)] px-8 py-3 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">
                        {{ __('Send my application') }}
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-site.page-shell>
