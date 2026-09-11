{{--
    After applying.

    Says what happens next — the checks — because that is the honest answer to
    "when do I start?", and the reference, so somebody who never receives the
    email has something to quote.
--}}
<x-site.page-shell :meta="$meta" :crumbs="$crumbs" :title="__('Thank you — we have your application')">
    <div class="max-w-2xl space-y-6">
        <p class="text-lg text-[var(--text-secondary)]">
            {{ $application->opportunity
                ? __('Thank you for applying to volunteer as :role. A confirmation is on its way to :email.', ['role' => $application->opportunity->title, 'email' => $application->email])
                : __('Thank you for applying to volunteer with us. A confirmation is on its way to :email.', ['email' => $application->email]) }}
        </p>

        <p class="text-[var(--text-secondary)]">
            {{ __('Because we work with children and vulnerable adults, every volunteer completes safeguarding checks before starting. Somebody from the team will be in touch about those.') }}
        </p>

        <p class="rounded-lg border border-[var(--border)] p-4 text-sm text-[var(--text-secondary)]">
            {{ __('Your reference is') }}
            <span class="font-mono font-semibold text-[var(--text-primary)]">{{ $application->reference }}</span>.
        </p>
    </div>
</x-site.page-shell>
