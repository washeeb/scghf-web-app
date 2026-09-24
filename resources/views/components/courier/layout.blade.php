{{--
    The courier portal's frame: a title, the rider's name, the pages.

    Big type and big buttons on purpose — this is read on a phone, outdoors,
    one-handed. Every word comes from Settings → Courier portal.
--}}
@props(['title'])

<x-layouts.app :noindex="true" :title="$title.setting('seo.title_suffix', '')">
    <div class="mx-auto w-full max-w-2xl px-4 py-6">
        <header class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <p class="eyebrow">{{ setting('courier.portal_title', __('Deliveries')) }}</p>
                <h1 class="mt-1 text-2xl tracking-tight text-[var(--text-primary)] sm:text-3xl">{{ $title }}</h1>
            </div>
            <p class="text-sm text-[var(--text-muted)]">{{ auth()->user()?->name }}</p>
        </header>

        <div class="mt-6 space-y-6">
            <x-site.status />

            @if ($errors->any())
                <div role="alert" class="rounded-[var(--radius-md)] border border-[var(--danger)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-primary)]">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</x-layouts.app>
