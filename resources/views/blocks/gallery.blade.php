{{-- A mosaic of photographs. --}}
@if (($gallery ?? null) && $gallery->items->isNotEmpty())
    <x-blocks.section :section="$section" :heading="$section->field('heading') ?: $gallery->title">
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($gallery->items as $item)
                @if ($item->media?->isPublishable())
                    <li>
                        <x-media.image :media="$item->media" size="card" class="aspect-square w-full rounded-md object-cover" />
                    </li>
                @endif
            @endforeach
        </ul>
    </x-blocks.section>
@endif
