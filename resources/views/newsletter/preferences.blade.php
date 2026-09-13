{{--
    The preference centre.

    Which lists to hear from, or none. One page, no account: the token in
    the email is the credential. A stale token is told so, not given a 404.
--}}
<x-site.page-shell :meta="$meta" :title="__('Your email preferences')">
    <div class="max-w-xl space-y-6">
        @if (session('status'))
            <p role="status" class="rounded-md border border-[var(--border)] bg-[var(--surface)] p-4 text-[var(--text-primary)]">{{ session('status') }}</p>
        @endif

        @if ($subscriber === null)
            <p class="text-[var(--text-secondary)]">{{ __('This link is no longer valid. If you would like to hear from us, sign up again from the foot of any page.') }}</p>
        @else
            <p class="text-[var(--text-secondary)]">
                {{ $subscriber->status === App\Models\Subscriber::STATUS_UNSUBSCRIBED
                    ? __('You are not receiving updates at the moment. Tick anything you would like to hear about and we will start again.')
                    : __('Tell us what you would like to hear about. Donation receipts and replies to messages you send us are separate, and always reach you.') }}
            </p>

            <form method="POST" action="{{ route('newsletter.preferences.update', $token) }}" class="space-y-5">
                @csrf

                <x-site.field name="name" :label="__('Your name')" :value="$subscriber->name" autocomplete="name" :hint="__('Optional. How we address you.')" />

                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-[var(--text-primary)]">{{ __('Send me') }}</legend>
                    @php $chosen = $subscriber->topics === null ? $newsletters->pluck('topic')->all() : (array) $subscriber->topics; @endphp
                    @foreach ($newsletters as $newsletter)
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" name="topics[]" value="{{ $newsletter->topic }}" @checked(in_array($newsletter->topic, $chosen, true)) class="mt-1 size-4 accent-[var(--brand-primary)]">
                            <span>
                                <span class="block font-medium text-[var(--text-primary)]">{{ $newsletter->name }}</span>
                                @if ($newsletter->description)<span class="block text-sm text-[var(--text-secondary)]">{{ $newsletter->description }}</span>@endif
                                @if ($newsletter->cadence)<span class="block text-xs text-[var(--text-muted)]">{{ $newsletter->cadence }}</span>@endif
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                <div class="flex flex-wrap items-center gap-4">
                    <button type="submit" class="rounded-md bg-[var(--brand-primary)] px-5 py-2.5 font-semibold text-[var(--text-on-brand)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">{{ __('Save') }}</button>
                    <button type="submit" name="stop" value="1" class="text-sm font-semibold text-[var(--text-secondary)] hover:underline">{{ __('Stop all updates') }}</button>
                </div>
            </form>
        @endif
    </div>
</x-site.page-shell>
