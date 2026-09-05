{{--
    The shell every signed-in account page sits in.

    ── Why the sub-navigation is here and not in the CMS ───────────────────────

    Every other menu on this site comes from the `menus` tables, because it is
    content the foundation edits. This one is not content: it is the set of
    pages this controller group defines, and a staff member who renamed or
    removed an item would be editing a route out of existence from the CMS.

    `aria-current="page"` is what tells a screen-reader user which of the three
    they are on. The visual highlight only tells the people who can see it.
--}}
@props(['title', 'user'])

@php
    $tabs = [
        ['route' => 'account.dashboard', 'label' => __('Overview')],
        ['route' => 'account.profile', 'label' => __('Your details')],
        ['route' => 'account.security', 'label' => __('Security')],
    ];
@endphp

<x-layouts.app :title="$title.setting('seo.title_suffix', '')">
    <div class="mx-auto w-full max-w-4xl px-4 py-10">
        <header class="space-y-1">
            <p class="text-sm text-[var(--text-muted)]">{{ __('Your account') }}</p>
            <h1 class="text-2xl font-semibold tracking-tight text-[var(--text-primary)]">{{ $title }}</h1>
        </header>

        <nav aria-label="{{ __('Account') }}" class="mt-6 border-b border-[var(--border)]">
            <ul class="-mb-px flex flex-wrap gap-1">
                @foreach ($tabs as $tab)
                    @php $isCurrent = request()->routeIs($tab['route']); @endphp
                    <li>
                        <a
                            href="{{ route($tab['route']) }}"
                            @if ($isCurrent) aria-current="page" @endif
                            @class([
                                'inline-block border-b-2 px-4 py-2.5 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]',
                                'border-[var(--brand-primary)] text-[var(--text-primary)]' => $isCurrent,
                                'border-transparent text-[var(--text-muted)] hover:text-[var(--text-primary)]' => ! $isCurrent,
                            ])
                        >{{ $tab['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="mt-8 space-y-6">
            <x-site.status />

            @if ($errors->any())
                <div role="alert" class="rounded-md border border-[var(--brand-secondary)] bg-[var(--surface)] px-4 py-3">
                    <p class="text-sm font-semibold text-[var(--text-primary)]">
                        {{ trans_choice(
                            'There is one problem to fix.|There are :count problems to fix.',
                            $errors->count(),
                            ['count' => $errors->count()],
                        ) }}
                    </p>
                    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-[var(--text-muted)]">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @unless ($user?->hasVerifiedEmail())
                {{-- Not a nag. An unverified account cannot see its giving
                     history, and saying so where they are is better than a
                     redirect they have to work out for themselves. --}}
                <div role="status" class="rounded-md border border-[var(--brand-secondary)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-primary)]">
                    {{ __('Your email address is not confirmed yet, so your giving history is hidden.') }}
                    <a href="{{ route('verification.notice') }}" class="font-semibold text-[var(--brand-primary)] hover:underline">{{ __('Confirm it now') }}</a>.
                </div>
            @endunless

            {{ $slot }}
        </div>
    </div>
</x-layouts.app>
