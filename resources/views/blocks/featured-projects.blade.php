{{-- Project cards. --}}
@if ($projects->isNotEmpty())
    <x-blocks.section :section="$section" :heading="$section->field('heading')" :intro="$section->field('intro')">
        <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($projects as $project)
                <li class="overflow-hidden rounded-lg border border-[var(--border)] bg-[var(--surface)]">
                    @if ($project->featuredImage?->isPublishable())
                        <x-media.image :media="$project->featuredImage" size="card" class="aspect-[3/2] w-full object-cover" />
                    @endif

                    <div class="p-5">
                        <h3 class="font-semibold text-[var(--text)]">{{ $project->title }}</h3>

                        @if ($project->summary)
                            <p class="mt-2 text-sm text-[var(--text-muted)]">{{ $project->summary }}</p>
                        @endif

                        <a href="{{ url('/projects/'.$project->slug) }}" class="mt-3 inline-block text-sm font-semibold text-[var(--brand-primary)] hover:underline">
                            {{ __('Read more') }}<span class="sr-only"> {{ __('about') }} {{ $project->title }}</span>
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-blocks.section>
@endif
