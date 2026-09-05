{{-- A strip of supporter marks. --}}
@if ($partners->isNotEmpty())
    <x-blocks.section :section="$section" :heading="$section->field('heading')">
        <ul class="flex flex-wrap items-center justify-center gap-8">
            @foreach ($partners as $partner)
                @if ($partner->logo?->isPublishable())
                    <li>
                        @if ($partner->website_url)
                            <a href="{{ $partner->website_url }}" target="_blank" rel="noopener noreferrer">
                                <x-media.image
                                    :media="$partner->logo"
                                    size="thumb"
                                    class="h-12 w-auto {{ $section->field('grayscale', true) ? 'grayscale transition hover:grayscale-0' : '' }}"
                                />
                                <span class="sr-only">{{ $partner->name }} {{ __('(opens in a new tab)') }}</span>
                            </a>
                        @else
                            <x-media.image
                                :media="$partner->logo"
                                size="thumb"
                                class="h-12 w-auto {{ $section->field('grayscale', true) ? 'grayscale' : '' }}"
                            />
                        @endif
                    </li>
                @endif
            @endforeach
        </ul>
    </x-blocks.section>
@endif
