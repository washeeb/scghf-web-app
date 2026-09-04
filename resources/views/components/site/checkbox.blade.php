{{--
    A checkbox with its label and, where it needs one, an explanation.

    The hidden `0` before the box is what makes an UNTICKED box mean something.
    A browser sends nothing at all for an unchecked checkbox, so without it
    "unsubscribe me" is indistinguishable from "this field was not on the form"
    — and the marketing preferences on this site are exactly that kind of field.
--}}
@props([
    'name',
    'label',
    'hint' => null,
    'checked' => false,
    'required' => false,
])

@php
    $id = $attributes->get('id', $name);
    $isChecked = (bool) old($name, $checked);
@endphp

<div class="space-y-1">
    <div class="flex items-start gap-2.5">
        <input type="hidden" name="{{ $name }}" value="0">

        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="checkbox"
            value="1"
            @checked($isChecked)
            @if ($required) required @endif
            @if ($hint) aria-describedby="{{ $id }}-hint" @endif
            @error($name) aria-invalid="true" @enderror
            class="mt-0.5 size-4 shrink-0 rounded border-[var(--border)] accent-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus)]"
        >

        <label for="{{ $id }}" class="text-sm text-[var(--text)]">
            {{ $label }}
            @if ($required)
                <span class="text-[var(--brand-secondary)]" aria-hidden="true">*</span>
                <span class="sr-only">({{ __('required') }})</span>
            @endif
        </label>
    </div>

    @if ($hint)
        <p id="{{ $id }}-hint" class="ml-[26px] text-xs text-[var(--text-muted)]">{{ $hint }}</p>
    @endif

    @error($name)
        <p role="alert" class="ml-[26px] text-sm text-[var(--brand-secondary)]">{{ $message }}</p>
    @enderror
</div>
