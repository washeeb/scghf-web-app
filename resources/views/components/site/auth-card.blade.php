{{--
    The narrow single-purpose page the auth screens sit in.

    ── The heading is an h1 and there is exactly one ───────────────────────────

    These pages have no other content, so the form's own heading is the page
    heading. Skipping it — or making it an h2 under an invisible h1 — is the
    most common heading error on sign-in pages, and it leaves a screen-reader
    user landing on a page with no announced purpose.

    ── The error summary is above the form, not only beside the fields ─────────

    Somebody using magnification sees a fraction of the screen at a time. A
    validation error attached to a field four scrolls down is a form that
    silently refuses to submit. The summary says how many and what, at the top,
    where focus lands.
--}}
@props(['title', 'subtitle' => null])

<x-layouts.app :title="$title.setting('seo.title_suffix', '')">
    <div class="mx-auto w-full max-w-md px-4 py-12 sm:py-16">
        <h1 class="text-2xl font-semibold tracking-tight text-[var(--text)]">{{ $title }}</h1>

        @if ($subtitle)
            <p class="mt-2 text-sm text-[var(--text-muted)]">{{ $subtitle }}</p>
        @endif

        <div class="mt-6 space-y-5">
            <x-site.status />

            @if ($errors->any())
                <div
                    role="alert"
                    tabindex="-1"
                    class="rounded-md border border-[var(--brand-secondary)] bg-[var(--surface)] px-4 py-3"
                >
                    <p class="text-sm font-semibold text-[var(--text)]">
                        {{ trans_choice(
                            'There is one problem with the form below.|There are :count problems with the form below.',
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

            {{ $slot }}
        </div>

        @isset($footer)
            <div class="mt-8 border-t border-[var(--border)] pt-6 text-sm text-[var(--text-muted)]">
                {{ $footer }}
            </div>
        @endisset
    </div>
</x-layouts.app>
