{{--
    The foundation's values, from the settings layer.

    Not typed into the block: they are the same seven everywhere they appear,
    and a block holding its own copy is a block that says something different
    from the About page after the first edit.
--}}
@php $values = collect(setting('general.core_values', []))->filter(); @endphp

@if ($values->isNotEmpty())
    <x-blocks.section :section="$section" :heading="$section->field('heading')" :intro="$section->field('intro')">
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($values as $value)
                <li class="rounded-lg border border-[var(--border)] px-5 py-4 font-medium text-[var(--text-primary)]">{{ $value }}</li>
            @endforeach
        </ul>
    </x-blocks.section>
@endif
