{{--
    Volunteering — the open roles.

    Somebody who wants to help and finds no role listed is not sent away: the
    general application is always offered, and it is treated as involving
    vulnerable contact, because for this foundation that is the safe
    assumption.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Volunteer')"
    :lead="setting('volunteering.intro', __('Give your time.'))"
>
    @if ($statement = setting('volunteering.safeguarding_statement'))
        <p class="mb-8 max-w-3xl rounded-lg border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-secondary)]">
            {{ $statement }}
            <x-site.policy-link slug="safeguarding" :label="__('Read our safeguarding policy.')" />
        </p>
    @endif

    @if ($roles->isEmpty())
        <p class="text-[var(--text-secondary)]">{{ __('No specific roles are open at the moment — but we are always glad to hear from people who want to help.') }}</p>
    @else
        <ul role="list" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($roles as $role)
                <li>
                    <a href="{{ route('volunteer.show', $role) }}" class="group flex h-full flex-col rounded-lg border border-[var(--border)] p-5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">
                        <p class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">
                            {{ collect([
                                match ($role->placement_type) {
                                    App\Models\VolunteerOpportunity::PLACEMENT_OFFICE => __('In the office'),
                                    App\Models\VolunteerOpportunity::PLACEMENT_EVENTS => __('At events'),
                                    App\Models\VolunteerOpportunity::PLACEMENT_REMOTE => __('Remote'),
                                    default => __('In the field'),
                                },
                                $role->region,
                            ])->filter()->implode(' · ') }}
                        </p>
                        <h2 class="mt-1 font-semibold text-[var(--text-primary)] group-hover:text-[var(--brand-primary)]">{{ $role->title }}</h2>
                        @if ($role->summary)
                            <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ $role->summary }}</p>
                        @endif
                        <p class="mt-auto pt-4 text-xs text-[var(--text-muted)]">
                            {{ collect([
                                $role->time_commitment,
                                $role->closes_on ? __('Apply by :date', ['date' => $role->closes_on->format('j M Y')]) : null,
                            ])->filter()->implode(' · ') }}
                        </p>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="mt-10">
        <a class="inline-block rounded-md bg-[var(--brand-primary)] px-6 py-3 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]" href="{{ route('volunteer.general') }}">
            {{ $roles->isEmpty() ? __('Tell us how you would like to help') : __('Nothing here for you? Apply anyway') }}
        </a>
    </p>
</x-site.page-shell>
