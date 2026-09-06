{{--
    Search results.

    ── The form is a GET, and that is the whole design ─────────────────────────

    A GET means the query is in the URL: shareable, bookmarkable, back-button
    friendly, and re-runnable by refreshing. A POSTed search gives none of that
    and asks "resubmit this form?" on every refresh.

    ── The result count is announced ───────────────────────────────────────────

    `role="status"` on the count, so a screen-reader user is told how many
    results there are rather than having to walk the list to find out — or worse,
    walk it to find out there are none.
--}}
<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="__('Search')">
    <form method="GET" action="{{ route('search') }}" class="mb-8 max-w-xl">
        <label for="q" class="mb-1 block text-sm font-medium text-[var(--text-primary)]">
            {{ __('What are you looking for?') }}
        </label>

        <div class="flex gap-2">
            <input
                id="q"
                name="q"
                type="search"
                value="{{ $term }}"
                autocomplete="off"
                class="w-full rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-[var(--text-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >

            <button
                type="submit"
                class="shrink-0 rounded-md bg-[var(--brand-primary)] px-5 py-2 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]"
            >{{ __('Search') }}</button>
        </div>
    </form>

    @if ($term !== '')
        <p role="status" class="mb-6 text-sm text-[var(--text-muted)]">
            {{ trans_choice(
                '{0}Nothing matched “:term”.|{1}One result for “:term”.|[2,*]:count results for “:term”.',
                $results->count(),
                ['count' => $results->count(), 'term' => $term],
            ) }}
        </p>

        @if ($results->isEmpty())
            {{-- A dead end is where somebody leaves. Two real ways forward
                 rather than "try a different search". --}}
            <p class="max-w-2xl text-[var(--text-secondary)]">
                {{ __('Try a shorter word, or') }}
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('faq') }}">{{ __('look through the questions we are asked most') }}</a>
                {{ __('—') }}
                <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('contact') }}">{{ __('or just ask us.') }}</a>
            </p>
        @else
            <ul role="list" class="max-w-2xl divide-y divide-[var(--border)] border-y border-[var(--border)]">
                @foreach ($results as $result)
                    <li class="py-4">
                        <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">{{ $result['kind'] }}</p>

                        <h2 class="mt-1 font-semibold">
                            <a class="text-[var(--brand-primary)] hover:underline" href="{{ $result['url'] }}">
                                {{ $result['title'] }}
                            </a>
                        </h2>

                        @if ($result['summary'])
                            <p class="mt-1 text-sm text-[var(--text-secondary)]">{{ $result['summary'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</x-site.page-shell>
