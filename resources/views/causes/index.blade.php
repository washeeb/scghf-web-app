{{--
    The appeals.

    Closed appeals are listed as well as open ones. A closed appeal is evidence
    the foundation finishes what it starts, which is worth more to a hesitant
    donor than another page of open asks.
--}}
<x-site.page-shell
    :meta="$meta"
    :crumbs="$crumbs"
    :title="__('Appeals')"
    :lead="__('What your giving pays for.')"
>
    @forelse ($causes as $cause)
        @if ($loop->first)
            <ul role="list" class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        @endif

        <li><x-site.cause-card :cause="$cause" :eager="$loop->index < 3" level="h2" /></li>

        @if ($loop->last)
            </ul>
        @endif
    @empty
        <p class="text-[var(--text-secondary)]">
            {{ __('Our appeals will be listed on this page.') }}
            <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('give') }}">
                {{ __('You can still give to our general work.') }}
            </a>
        </p>
    @endforelse
</x-site.page-shell>
