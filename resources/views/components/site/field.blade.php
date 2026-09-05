{{--
    One labelled form control.

    ── Every part of this is an accessibility requirement, not styling ─────────

    The label is a real <label for>, never a placeholder. A placeholder
    disappears the moment somebody types, which for anybody who loses their
    place — and on a phone, in a hurry, that is most people — leaves an
    unlabelled box.

    The error is tied to the input by `aria-describedby` and announced with
    `role="alert"`, so a screen-reader user hears WHICH field failed rather than
    a list of complaints floating above a form. The hint is tied the same way.

    `aria-invalid` is what tells assistive technology the field is in an error
    state; the red border only tells people who can see it, and colour is never
    the only signal.

    @param name      the field name, which is also the id and the error key
    @param label     visible label text
    @param type      input type; `textarea` renders a textarea instead
    @param hint      optional help text, read out with the field
    @param required  adds the attribute AND the visible marker; they must agree
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'hint' => null,
    'required' => false,
    'value' => null,
    'autocomplete' => null,
])

@php
    $id = $attributes->get('id', $name);
    $hasError = $errors->has($name);
    $describedBy = collect([
        $hint ? $id.'-hint' : null,
        $hasError ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<div class="space-y-1.5">
    <label for="{{ $id }}" class="block text-sm font-medium text-[var(--text-primary)]">
        {{ $label }}
        @if ($required)
            <span class="text-[var(--brand-secondary)]" aria-hidden="true">*</span>
            <span class="sr-only">({{ __('required') }})</span>
        @endif
    </label>

    @if ($hint)
        <p id="{{ $id }}-hint" class="text-xs text-[var(--text-muted)]">{{ $hint }}</p>
    @endif

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($required) required @endif
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @if ($hasError) aria-invalid="true" @endif
        value="{{ old($name, $value) }}"
        {{ $attributes->class([
            'w-full rounded-md border bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)]',
            'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]',
            'border-[var(--border)]' => ! $hasError,
            'border-[var(--brand-secondary)]' => $hasError,
        ]) }}
    >

    @error($name)
        <p id="{{ $id }}-error" role="alert" class="text-sm text-[var(--brand-secondary)]">{{ $message }}</p>
    @enderror
</div>
