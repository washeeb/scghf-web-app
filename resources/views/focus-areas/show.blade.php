{{--
    One area of work: what we are doing in it, and what somebody can give to.

    The appeals section is not decoration. A page describing work with no way to
    support it has told a visitor what to care about and then stopped.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="$focusArea->name"
    :lead="$focusArea->description"
>
    <section aria-labelledby="projects-heading">
        <h2 id="projects-heading" class="text-xl font-semibold text-[var(--text-primary)]">
            {{ __('What we are doing') }}
        </h2>

        @forelse ($projects as $project)
            @if ($loop->first)
                <ul role="list" class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            @endif

            <li><x-site.project-card :project="$project" :eager="$loop->index < 3" /></li>

            @if ($loop->last)
                </ul>
            @endif
        @empty
            <p class="mt-4 text-[var(--text-secondary)]">
                {{ __('We have not published a project in this area yet.') }}
            </p>
        @endforelse
    </section>

    @if ($causes->isNotEmpty())
        <section class="mt-16" aria-labelledby="causes-heading">
            <h2 id="causes-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                {{ __('Support this work') }}
            </h2>

            <ul role="list" class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($causes as $cause)
                    <li><x-site.cause-card :cause="$cause" /></li>
                @endforeach
            </ul>
        </section>
    @endif
</x-site.page-shell>
