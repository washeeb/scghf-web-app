{{--
    The flash message strip.

    `role="status"` rather than `role="alert"`: this reports something that has
    already finished — saved, sent, signed out — and `alert` interrupts whatever
    a screen reader is currently saying, which for a confirmation is rude rather
    than useful. Errors on individual fields use `alert`, because those do need
    interrupting.

    Rendered only when there is something to say. An empty bordered box at the
    top of every page reads as a component that failed to load.
--}}
@props(['status' => null])

@php $message = $status ?? session('status'); @endphp

@if ($message)
    <div
        role="status"
        class="rounded-md border border-[var(--brand-primary)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text)]"
    >
        {{ $message }}
    </div>
@endif
