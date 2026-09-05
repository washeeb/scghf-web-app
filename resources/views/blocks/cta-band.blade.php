{{-- A full-width band with one thing to do. --}}
<x-blocks.section :section="$section">
    <div class="flex flex-col items-start gap-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $section->field('heading') }}</h2>

            @if ($body = $section->field('body'))
                <p class="mt-2 max-w-2xl opacity-90">{{ $body }}</p>
            @endif
        </div>

        @if (filled($section->field('cta_label')) && filled($section->field('cta_url')))
            <a
                href="{{ $section->field('cta_url') }}"
                class="shrink-0 rounded-md bg-[var(--brand-secondary)] px-6 py-3 font-semibold text-[var(--text-on-secondary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >{{ $section->field('cta_label') }}</a>
        @endif
    </div>
</x-blocks.section>
