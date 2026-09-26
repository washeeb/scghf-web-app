{{--
    Who runs the foundation.

    Grouped by department, with anybody uncategorised in a final group rather
    than dropped — a trustee an editor forgot to file is a trustee missing from
    the page, and on a governance page that is the wrong kind of missing.

    Only `public_email` is ever shown. Never the address on a linked account.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Our team')"
    :lead="__('The people who do this work.')"
>
    @php
        $groups = $departments
            ->map(fn ($department) => [
                'heading' => $department->name,
                'description' => $department->description,
                'people' => $members->where('team_department_id', $department->getKey()),
            ])
            ->filter(fn (array $group): bool => $group['people']->isNotEmpty())
            ->values();

        $unfiled = $members->whereNull('team_department_id');

        if ($unfiled->isNotEmpty()) {
            $groups->push([
                'heading' => $groups->isEmpty() ? null : __('And also'),
                'description' => null,
                'people' => $unfiled,
            ]);
        }
    @endphp

    @forelse ($groups as $group)
        <section class="mb-12" @if ($group['heading']) aria-labelledby="team-{{ $loop->index }}" @endif>
            @if ($group['heading'])
                <h2 id="team-{{ $loop->index }}" class="text-xl font-semibold text-[var(--text-primary)]">
                    {{ $group['heading'] }}
                </h2>
            @endif

            @if ($group['description'])
                <p class="mt-1 max-w-2xl text-[var(--text-secondary)]">{{ $group['description'] }}</p>
            @endif

            <ul role="list" class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($group['people'] as $member)
                    <li>
                        @if ($member->photo)
                            <x-media.image
                                :media="$member->photo"
                                size="card"
                                class="mb-3 aspect-square w-full rounded-lg object-cover"
                            />
                        @endif

                        <h3 class="font-semibold text-[var(--text-primary)]">{{ $member->name }}</h3>
                        <p class="text-sm text-[var(--text-muted)]">{{ $member->role_title }}</p>

                        @if ($member->bio)
                            <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ $member->bio }}</p>
                        @endif

                        @if ($member->public_email || $member->linkedin_url)
                            <ul role="list" class="mt-2 flex flex-wrap gap-3 text-sm">
                                @if ($member->public_email)
                                    <li>
                                        <a class="text-[var(--brand-primary)] hover:underline" href="mailto:{{ $member->public_email }}">
                                            {{ __('Email') }}
                                        </a>
                                    </li>
                                @endif

                                @if ($member->linkedin_url)
                                    <li>
                                        <a
                                            class="text-[var(--brand-primary)] hover:underline"
                                            href="{{ $member->linkedin_url }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >{{ __('LinkedIn') }}</a>
                                    </li>
                                @endif
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="text-[var(--text-secondary)]">{{ __('Our team will be listed here.') }}</p>
    @endforelse
</x-site.page-shell>
