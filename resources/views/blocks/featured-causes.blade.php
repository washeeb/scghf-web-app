{{-- Cause cards with live progress towards goal. --}}
@if ($causes->isNotEmpty())
    <x-blocks.section :section="$section" :eyebrow="$section->field('eyebrow')" :heading="$section->field('heading')" :intro="$section->field('intro')">
        <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($causes as $cause)
                <li class="flex flex-col overflow-hidden rounded-[var(--radius-xl)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow-sm)] transition hover:shadow-[var(--shadow-md)]">
                    @if ($cause->featuredImage?->isPublishable())
                        <x-media.image :media="$cause->featuredImage" size="card" credit="title" class="aspect-[4/3] w-full object-cover" />
                    @endif

                    <div class="flex flex-1 flex-col p-6">
                        <h3 class="text-lg font-semibold text-[var(--text-primary)]">{{ $cause->title }}</h3>

                        @if ($cause->summary)
                            <p class="mt-2 text-sm text-[var(--text-muted)]">{{ $cause->summary }}</p>
                        @endif

                        @php $percent = $cause->progressPercent(); @endphp

                        @if ($percent !== null)
                            {{-- A real progress bar, announced as one. A bare
                                 coloured div tells a screen-reader user
                                 nothing. --}}
                            <div class="mt-4">
                                <div
                                    role="progressbar"
                                    aria-valuenow="{{ $percent }}"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    aria-label="{{ __('Raised so far towards :cause', ['cause' => $cause->title]) }}"
                                    class="h-2 w-full overflow-hidden rounded-full bg-[var(--surface-sunken)]"
                                >
                                    <span class="block h-full rounded-full bg-[var(--brand-primary)]" style="width: {{ $percent }}%"></span>
                                </div>

                                <p class="mt-2 text-sm text-[var(--text-muted)]">
                                    {{ __(':raised raised of :goal', ['raised' => $cause->raisedAmount(), 'goal' => $cause->goal]) }}
                                </p>
                            </div>
                        @endif

                        <a
                            href="{{ url('/causes/'.$cause->slug) }}"
                            class="btn btn-sm btn-accent mt-5 self-start"
                        >
                            {{ $section->field('cta_label') ?: __('Support this') }}
                            <span class="sr-only">— {{ $cause->title }}</span>
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-blocks.section>
@endif
