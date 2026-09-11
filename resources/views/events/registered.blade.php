{{--
    After registering.

    Says whether the place is confirmed or waitlisted, and the reference, so
    somebody who never receives the email still knows where they stand.
--}}
@php $waitlisted = $registration->status === App\Models\EventRegistration::STATUS_WAITLISTED; @endphp

<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="$waitlisted ? __('You are on the waiting list') : __('You are registered')"
>
    <div class="max-w-2xl space-y-6">
        <x-site.status />

        <p class="text-lg text-[var(--text-secondary)]">
            @if ($waitlisted)
                {{ __(':event is full, so we have added you to the waiting list. If a place opens up we will email :email.', ['event' => $event->title, 'email' => $registration->email]) }}
            @else
                {{ __('Your place at :event on :date is confirmed. A confirmation is on its way to :email.', [
                    'event' => $event->title,
                    'date' => $event->starts_at->format('l j F Y'),
                    'email' => $registration->email,
                ]) }}
            @endif
        </p>

        @if ($event->is_online && ! $waitlisted)
            <p class="text-[var(--text-secondary)]">{{ __('The link to join is in the email.') }}</p>
        @endif

        <p class="rounded-lg border border-[var(--border)] p-4 text-sm text-[var(--text-secondary)]">
            {{ __('Your reference is') }}
            <span class="font-mono font-semibold text-[var(--text-primary)]">{{ $registration->reference }}</span>.
            {{ __('If you can no longer come, please let us know so we can offer the place to somebody else.') }}
        </p>

        <p>
            <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('events.show', $event) }}">{{ __('Back to the event') }}</a>
        </p>
    </div>
</x-site.page-shell>
