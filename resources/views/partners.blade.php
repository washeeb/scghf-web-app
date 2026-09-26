{{--
    Who we work with.

    Past partners are kept and shown separately rather than deleted. "Who have
    you worked with?" is a question a funder asks, and a partner removed because
    the work finished is a piece of the foundation's history gone.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Our partners')"
    :lead="__('None of this happens alone.')"
>
    @forelse ($current as $partner)
        @if ($loop->first)
            <ul role="list" class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        @endif

        <li class="rounded-lg border border-[var(--border)] p-5">
            @if ($partner->logo)
                <x-media.image :media="$partner->logo" size="thumb" class="mb-3 h-12 w-auto" />
            @endif

            <h2 class="font-semibold text-[var(--text-primary)]">
                @if ($partner->website_url)
                    <a
                        class="hover:text-[var(--brand-primary)] hover:underline"
                        href="{{ $partner->website_url }}"
                        target="_blank"
                        rel="noopener noreferrer"
                    >{{ $partner->name }}</a>
                @else
                    {{ $partner->name }}
                @endif
            </h2>

            @if ($partner->description)
                <p class="mt-2 text-sm text-[var(--text-secondary)]">{{ $partner->description }}</p>
            @endif

            @if ($partner->partnership_started_on)
                <p class="mt-2 text-xs text-[var(--text-muted)]">
                    {{ __('Working together since :date', ['date' => $partner->partnership_started_on->format('F Y')]) }}
                </p>
            @endif
        </li>

        @if ($loop->last)
            </ul>
        @endif
    @empty
        <p class="text-[var(--text-secondary)]">{{ __('Our partners will be listed here.') }}</p>
    @endforelse

    @if ($past->isNotEmpty())
        <section class="mt-14" aria-labelledby="past-partners">
            <h2 id="past-partners" class="text-xl font-semibold text-[var(--text-primary)]">
                {{ __('We have also worked with') }}
            </h2>

            <ul role="list" class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-[var(--text-secondary)]">
                @foreach ($past as $partner)
                    <li>{{ $partner->name }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</x-site.page-shell>
