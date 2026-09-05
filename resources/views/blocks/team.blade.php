{{-- Trustees, leadership and staff. --}}
@if ($members->isNotEmpty())
    <x-blocks.section :section="$section" :heading="$section->field('heading')">
        <ul class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($members as $member)
                <li class="text-center">
                    @if ($member->photo?->isPublishable())
                        <x-media.image :media="$member->photo" size="card" class="mx-auto aspect-square w-32 rounded-full object-cover" />
                    @endif

                    <h3 class="mt-4 font-semibold text-[var(--text)]">{{ $member->name }}</h3>

                    @if ($member->role_title)
                        <p class="text-sm text-[var(--text-muted)]">{{ $member->role_title }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-blocks.section>
@endif
