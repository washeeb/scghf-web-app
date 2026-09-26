{{--
    What the foundation works on.

    The first question anybody asks of a charity they have not heard of, and one
    a page of project titles does not answer.

    An area with no published project yet is still listed: it is a true
    statement about the organisation, and hiding it would make the page describe
    the CMS rather than the foundation.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('What we do')"
    :lead="__('The areas we work in, and what we are doing in each.')"
>
    @forelse ($focusAreas as $focusArea)
        @if ($loop->first)
            <ul role="list" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        @endif

        <li>
            <a
                href="{{ route('focus-areas.show', $focusArea) }}"
                class="group flex h-full flex-col rounded-lg border border-[var(--border)] p-6 hover:border-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >
                <h2 class="font-semibold text-[var(--text-primary)] group-hover:text-[var(--brand-primary)]">
                    {{ $focusArea->name }}
                </h2>

                @if ($focusArea->description)
                    <p class="mt-2 flex-1 text-sm text-[var(--text-secondary)]">{{ $focusArea->description }}</p>
                @endif

                <p class="mt-4 text-xs uppercase tracking-wide text-[var(--text-muted)]">
                    {{ trans_choice(
                        '{0}No published projects yet|{1}1 project|[2,*]:count projects',
                        $focusArea->projects_count,
                        ['count' => $focusArea->projects_count],
                    ) }}
                </p>
            </a>
        </li>

        @if ($loop->last)
            </ul>
        @endif
    @empty
        <p class="text-[var(--text-secondary)]">{{ __('Our areas of work will be listed here.') }}</p>
    @endforelse
</x-site.page-shell>
