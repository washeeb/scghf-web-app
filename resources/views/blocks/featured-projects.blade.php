{{-- Project cards. --}}
@if ($projects->isNotEmpty())
    <x-blocks.section :section="$section" :eyebrow="$section->field('eyebrow')" :heading="$section->field('heading')" :intro="$section->field('intro')">
        <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($projects as $project)
                <li class="overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow-sm)] transition hover:shadow-[var(--shadow-md)]">
                    @if ($project->featuredImage?->isPublishable())
                        <x-media.image :media="$project->featuredImage" size="card" credit="title" class="aspect-[4/3] w-full object-cover" />
                    @endif

                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-[var(--text-primary)]">{{ $project->title }}</h3>

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
