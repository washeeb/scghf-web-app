{{--
    A structured enquiry form — partner with us, corporate giving, donate
    goods, or fundraise for us — chosen by the editor when placing the block.

    The fields come from `EnquiryKinds`, the same list the controller
    validates against, so the form and the validation cannot disagree. A plain
    POST; errors are rendered on the page; nothing here needs a script.

    Rendered only when the route exists to receive it — a form posting to a
    404 is worse than no form, because somebody types their offer into it and
    believes it was sent.
--}}
@php
    $kind = (string) $section->field('kind', 'partner');
    $spec = App\Community\EnquiryKinds::has($kind) ? App\Community\EnquiryKinds::get($kind) : null;
@endphp

@if ($spec && Route::has('enquiries.store'))
    <x-blocks.section :section="$section" :eyebrow="$section->field('eyebrow')" :heading="$section->field('heading', $spec['label'])" :intro="$section->field('intro', $spec['intro'])">
        <div class="max-w-2xl">
            <x-site.status />

            <form method="POST" action="{{ route('enquiries.store', $kind) }}" class="mt-4 space-y-5">
                @csrf
                <x-honeypot />

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-site.field name="name" :label="__('Your name')" required autocomplete="name" :value="auth()->user()?->name" />
                    <x-site.field name="email" type="email" :label="__('Email address')" required autocomplete="email" :value="auth()->user()?->email" />
                </div>

                <x-site.field name="phone" type="tel" :label="__('Phone number')" autocomplete="tel" :hint="__('Optional. 024 123 4567 or +233 24 123 4567.')" />

                @foreach ($spec['fields'] as $name => $field)
                    @if ($field['type'] === 'select')
                        <div class="space-y-1.5">
                            <label for="{{ $name }}" class="block text-sm font-medium text-[var(--text-primary)]">
                                {{ $field['label'] }}
                                @if ($field['required'] ?? false)
                                    <span class="text-[var(--brand-secondary)]" aria-hidden="true">*</span>
                                    <span class="sr-only">({{ __('required') }})</span>
                                @endif
                            </label>
                            <select
                                id="{{ $name }}"
                                name="{{ $name }}"
                                @if ($field['required'] ?? false) required @endif
                                @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
                                class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)]"
                            >
                                <option value="">{{ __('Choose') }}</option>
                                @foreach ($field['options'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old($name) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error($name)
                                <p id="{{ $name }}-error" role="alert" class="text-sm text-[var(--brand-secondary)]">{{ $message }}</p>
                            @enderror
                        </div>
                    @else
                        <x-site.field
                            :name="$name"
                            :type="$field['type']"
                            :rows="4"
                            :label="$field['label']"
                            :required="$field['required'] ?? false"
                            :hint="$field['hint'] ?? null"
                        />
                    @endif
                @endforeach

                <x-site.field name="message" type="textarea" :rows="3" :label="__('Anything else?')" />

                <x-site.checkbox
                    name="consent"
                    required
                    :label="setting('compliance.contact_consent_text', __('I agree that my details may be stored in order to reply to me.'))"
                />

                <button type="submit" class="rounded-md bg-[var(--brand-primary)] px-6 py-3 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">
                    {{ $section->field('button_label', __('Send')) }}
                </button>
            </form>
        </div>
    </x-blocks.section>
@endif
