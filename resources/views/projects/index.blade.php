{{--
    The projects, with filters.

    ── Every filter is a link, not a script ────────────────────────────────────

    Each combination is a real URL — shareable, bookmarkable, crawlable, and
    working before any JavaScript has loaded. A filter panel that needs a script
    shows an unfiltered list to somebody on a slow connection and never says why.

    The options come from the data: the regions offered are the regions projects
    are actually in. A dropdown of all sixteen Ghanaian regions on a site with
    work in three is thirteen dead ends.
--}}
@php
    $active = collect($filters)->filter(fn ($value) => $value !== '');
@endphp

<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Our projects')"
    :lead="__('What we are doing, where, and how far along it is.')"
>
    <form method="GET" action="{{ route('projects.index') }}" class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label for="focus" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Area of work') }}</label>
            <select id="focus" name="focus" class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-sm text-[var(--text-primary)]">
                <option value="">{{ __('All') }}</option>
                @foreach ($focusAreas as $option)
                    <option value="{{ $option->slug }}" @selected($filters['focus'] === $option->slug)>{{ $option->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="region" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Region') }}</label>
            <select id="region" name="region" class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-sm text-[var(--text-primary)]">
                <option value="">{{ __('Anywhere') }}</option>
                @foreach ($regions as $option)
                    <option value="{{ $option }}" @selected($filters['region'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="status" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Status') }}</label>
            <select id="status" name="status" class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-sm text-[var(--text-primary)]">
                <option value="">{{ __('Any') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="year" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">{{ __('Running in') }}</label>
            <select id="year" name="year" class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-sm text-[var(--text-primary)]">
                <option value="">{{ __('Any year') }}</option>
                @foreach ($years as $option)
                    <option value="{{ $option }}" @selected($filters['year'] === (string) $option)>{{ $option }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-end gap-2">
            <button
                type="submit"
                class="rounded-md bg-[var(--brand-primary)] px-4 py-2 text-sm font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >{{ __('Filter') }}</button>

            @if ($active->isNotEmpty())
                {{-- A way back out. A filtered list with no reset is one people
                     escape by editing the address bar or leaving. --}}
                <a href="{{ route('projects.index') }}" class="px-2 py-2 text-sm text-[var(--text-muted)] hover:underline">
                    {{ __('Clear') }}
                </a>
            @endif
        </div>
    </form>

    <p role="status" class="mb-6 text-sm text-[var(--text-muted)]">
        {{ trans_choice('{0}No projects match|{1}1 project|[2,*]:count projects', $projects->total(), ['count' => $projects->total()]) }}
    </p>

    @forelse ($projects as $project)
        @if ($loop->first)
            <ul role="list" class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        @endif

        <li><x-site.project-card :project="$project" :eager="$loop->index < 3" /></li>

        @if ($loop->last)
            </ul>
        @endif
    @empty
        <p class="text-[var(--text-secondary)]">
            {{ $active->isNotEmpty()
                ? __('Nothing matches those filters. Try clearing one of them.')
                : __('Our projects will be listed on this page.') }}
        </p>
    @endforelse

    @if ($projects->hasPages())
        <div class="mt-10">{{ $projects->onEachSide(1)->links() }}</div>
    @endif
</x-site.page-shell>
