{{--
    One project — and this page is the transparency page.

    Budget, progress, milestones, locations, partners and documents together are
    what let somebody CHECK a claim rather than take it. A foundation page that
    says "we built boreholes" and shows none of that is asking for trust it has
    not offered any way to verify.

    Milestones are `is_public` only: an internal target the team missed is not a
    promise the foundation made to the public.
--}}
<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="$project->title" :lead="$project->summary">
    @if ($project->featuredImage)
        <x-media.image
            :media="$project->featuredImage"
            size="hero"
            :eager="true"
            class="mb-8 w-full rounded-lg object-cover"
        />
    @endif

    <div class="grid gap-12 lg:grid-cols-[2fr_1fr]">
        <div class="max-w-3xl space-y-10">
            @if ($project->description)
                <div class="prose-scghf space-y-4 text-[var(--text-primary)]">{!! $project->description !!}</div>
            @endif

            @if ($milestones->isNotEmpty())
                <section aria-labelledby="milestones-heading">
                    <h2 id="milestones-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                        {{ __('Where we have got to') }}
                    </h2>

                    <ol role="list" class="mt-4 space-y-4 border-l border-[var(--border)] pl-5">
                        @foreach ($milestones as $milestone)
                            <li>
                                <p class="font-medium text-[var(--text-primary)]">
                                    {{ $milestone->title }}

                                    @if ($milestone->achieved_on)
                                        <span class="ml-2 text-sm font-normal text-[var(--success)]">
                                            {{ __('done :date', ['date' => $milestone->achieved_on->format('M Y')]) }}
                                        </span>
                                    @elseif ($milestone->due_on)
                                        <span class="ml-2 text-sm font-normal text-[var(--text-muted)]">
                                            {{ __('due :date', ['date' => $milestone->due_on->format('M Y')]) }}
                                        </span>
                                    @endif
                                </p>

                                @if ($milestone->description)
                                    <p class="mt-1 text-sm text-[var(--text-secondary)]">{{ $milestone->description }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif

            @if ($updates->isNotEmpty())
                <section aria-labelledby="updates-heading">
                    <h2 id="updates-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                        {{ __('Updates') }}
                    </h2>

                    <ul role="list" class="mt-4 space-y-6">
                        @foreach ($updates as $update)
                            <li>
                                <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                    {{ $update->published_at?->toFormattedDateString() }}
                                </p>
                                <h3 class="mt-1 font-semibold text-[var(--text-primary)]">{{ $update->title }}</h3>
                                <div class="prose-scghf mt-2 text-sm text-[var(--text-secondary)]">{!! $update->body !!}</div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($project->documents->isNotEmpty())
                <section aria-labelledby="documents-heading">
                    <h2 id="documents-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                        {{ __('Documents') }}
                    </h2>

                    <ul role="list" class="mt-4 space-y-2">
                        @foreach ($project->documents as $document)
                            <li>
                                <a class="text-[var(--brand-primary)] hover:underline" href="{{ route('documents.download', $document) }}">
                                    {{ $document->title }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside class="space-y-8 text-sm">
            <div class="rounded-lg border border-[var(--border)] p-5">
                <h2 class="font-semibold text-[var(--text-primary)]">{{ __('At a glance') }}</h2>

                <dl class="mt-3 space-y-2 text-[var(--text-secondary)]">
                    <div class="flex justify-between gap-4">
                        <dt>{{ __('Status') }}</dt>
                        <dd class="font-medium text-[var(--text-primary)]">{{ $project->status->label() }}</dd>
                    </div>

                    @if ($project->starts_on)
                        <div class="flex justify-between gap-4">
                            <dt>{{ __('Started') }}</dt>
                            <dd>{{ $project->starts_on->format('M Y') }}</dd>
                        </div>
                    @endif

                    @if ($project->ends_on)
                        <div class="flex justify-between gap-4">
                            <dt>{{ __('Ends') }}</dt>
                            <dd>{{ $project->ends_on->format('M Y') }}</dd>
                        </div>
                    @endif

                    @if ($project->budget)
                        <div class="flex justify-between gap-4">
                            <dt>{{ __('Budget') }}</dt>
                            <dd>{{ $project->budget->format() }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($project->locations->isNotEmpty())
                <div>
                    <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Where') }}</h2>
                    <ul role="list" class="mt-2 space-y-1 text-[var(--text-secondary)]">
                        @foreach ($project->locations as $location)
                            <li>{{ collect([$location->community, $location->district, $location->region])->filter()->implode(', ') }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($project->focusAreas->isNotEmpty())
                <div>
                    <h2 class="font-semibold text-[var(--text-primary)]">{{ __('Areas of work') }}</h2>
                    <ul role="list" class="mt-2 flex flex-wrap gap-2">
                        @foreach ($project->focusAreas as $focusArea)
                            <li>
                                <a
                                    class="rounded-full border border-[var(--border)] px-3 py-1 text-xs text-[var(--text-secondary)] hover:border-[var(--brand-primary)]"
                                    href="{{ route('focus-areas.show', $focusArea) }}"
                                >{{ $focusArea->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($project->partners->isNotEmpty())
                <div>
                    <h2 class="font-semibold text-[var(--text-primary)]">{{ __('With') }}</h2>
                    <ul role="list" class="mt-2 space-y-1 text-[var(--text-secondary)]">
                        @foreach ($project->partners as $partner)
                            <li>{{ $partner->name }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </aside>
    </div>

    @if ($causes->isNotEmpty())
        <section class="mt-16" aria-labelledby="support-heading">
            <h2 id="support-heading" class="text-xl font-semibold text-[var(--text-primary)]">
                {{ __('Support this project') }}
            </h2>

            <ul role="list" class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($causes as $cause)
                    <li><x-site.cause-card :cause="$cause" /></li>
                @endforeach
            </ul>
        </section>
    @endif
</x-site.page-shell>
