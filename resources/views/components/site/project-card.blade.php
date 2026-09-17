{{--
    One project, in a list.

    The whole card is a single link, so the tap target is the card rather than a
    four-word title — the difference between a listing that works on a phone and
    one that does not.

    @param project an App\Models\Project
    @param eager   true only for the images above the fold
--}}
@props(['project', 'eager' => false, 'level' => 'h3'])

<a
    href="{{ route('projects.show', $project) }}"
    class="group block h-full rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
>
    @if ($project->featuredImage)
        <x-media.image
            :media="$project->featuredImage"
            size="card"
            :eager="$eager"
            class="mb-3 aspect-[3/2] w-full rounded-lg object-cover"
        />
    @endif

    <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">
        {{ collect([
            $project->status->label(),
            $project->primaryLocation()?->region,
        ])->filter()->implode(' · ') }}
    </p>

    <{{ $level }} class="mt-1 font-semibold text-[var(--text-primary)] group-hover:text-[var(--brand-primary)]">
        {{ $project->title }}
    </{{ $level }}>

    @if ($project->summary)
        <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ $project->summary }}</p>
    @endif
</a>
