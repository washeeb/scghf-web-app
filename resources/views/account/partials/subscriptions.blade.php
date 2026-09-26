@if ($subscriptions->isEmpty())
    <p class="text-[var(--text-secondary)]">
        {{ __('You do not have a regular gift set up.') }}
        <a class="font-semibold text-[var(--brand-primary)] hover:underline" href="{{ route('donate') }}">{{ __('Set one up') }}</a>
        {{ __('— even a small monthly gift is what lets us plan.') }}
    </p>
@endif

@foreach ($subscriptions as $subscription)
    @php
        $status = $subscription->status;
        $finished = $status->isFinished();
    @endphp

    <section class="rounded-lg border border-[var(--border)] p-5" aria-labelledby="sub-{{ $subscription->ulid }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="sub-{{ $subscription->ulid }}" class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ $subscription->amount->format() }} {{ $intervalWord($subscription) }}
                </h2>
                <p class="text-sm text-[var(--text-secondary)]">
                    {{ __('To') }} {{ $subscription->cause?->title ?? __('our general work') }}
                    · {{ __('since :date', ['date' => $subscription->started_on?->format('j F Y')]) }}
                    · {{ __('reference') }} <span class="font-mono">{{ $subscription->reference }}</span>
                </p>
            </div>

            <span @class([
                'rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide',
                'bg-[var(--brand-primary)] text-[var(--text-on-brand)]' => $status->value === 'active',
                'border border-[var(--warning)] text-[var(--warning)]' => in_array($status->value, ['paused', 'failing'], true),
                'border border-[var(--border)] text-[var(--text-muted)]' => $finished,
            ])>{{ $status->label() }}</span>
        </div>

        <p class="mt-3 text-sm text-[var(--text-secondary)]">
            @if ($finished)
                {{ __('Ended on :date.', ['date' => $subscription->ended_on?->format('j F Y')]) }}
            @elseif ($status->value === 'paused')
                {{ __('Paused. Nothing is taken until you start it again.') }}
            @elseif ($status->value === 'failing')
                {{ __('The last gift did not go through. We will try again on :date; if your card or wallet has changed, set up a new gift and stop this one.', ['date' => $subscription->next_charge_on?->format('j F Y')]) }}
            @else
                {{ __('Next gift on :date.', ['date' => $subscription->next_charge_on?->format('j F Y')]) }}
            @endif
            {{ __('Given so far: :total.', ['total' => $subscription->totalCharged()->format()]) }}
        </p>

        @if ($subscription->charges->isNotEmpty())
            <ul role="list" class="mt-3 divide-y divide-[var(--border)] border-y border-[var(--border)] text-sm">
                @foreach ($subscription->charges as $charge)
                    <li class="flex justify-between gap-4 py-1.5">
                        <span class="text-[var(--text-secondary)]">{{ $charge->scheduled_on?->format('j M Y') }}</span>
                        <span class="text-[var(--text-primary)]">{{ $charge->amount->format() }}</span>
                        <span @class(['text-xs uppercase tracking-wide', 'text-[var(--success)]' => $charge->status === 'succeeded', 'text-[var(--danger)]' => $charge->status === 'failed', 'text-[var(--text-muted)]' => ! in_array($charge->status, ['succeeded', 'failed'], true)])>{{ $charge->status }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        @unless ($finished)
            <div class="mt-4 flex flex-wrap items-end gap-3">
                @if ($status->value === 'active' || $status->value === 'failing')
                    <form method="POST" action="{{ $action('giving.pause', $subscription) }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-[var(--border)] px-4 py-2 text-sm font-semibold text-[var(--text-primary)]">{{ __('Pause') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ $action('giving.resume', $subscription) }}">
                        @csrf
                        <button type="submit" class="rounded-md bg-[var(--brand-primary)] px-4 py-2 text-sm font-semibold text-[var(--text-on-brand)]">{{ __('Start again') }}</button>
                    </form>
                @endif

                <form method="POST" action="{{ $action('giving.amount', $subscription) }}" class="flex items-end gap-2">
                    @csrf
                    <div>
                        <label for="amount-{{ $subscription->ulid }}" class="mb-1 block text-xs font-medium text-[var(--text-primary)]">{{ __('Change the amount') }}</label>
                        <div class="flex items-center gap-1">
                            <span class="text-sm text-[var(--text-muted)]">GH₵</span>
                            <input id="amount-{{ $subscription->ulid }}" name="amount" type="number" step="0.01" min="0.01" inputmode="decimal" value="{{ $subscription->amount->toMajorString() }}"
                                   class="w-28 rounded-md border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-sm text-[var(--text-primary)]">
                        </div>
                    </div>
                    <button type="submit" class="rounded-md border border-[var(--border)] px-4 py-2 text-sm font-semibold text-[var(--text-primary)]">{{ __('Save') }}</button>
                </form>
            </div>

            <details class="mt-4">
                <summary class="cursor-pointer text-sm text-[var(--text-secondary)] underline">{{ __('Stop this gift') }}</summary>
                <form method="POST" action="{{ $action('giving.cancel', $subscription) }}" class="mt-3 space-y-3">
                    @csrf
                    <x-site.field name="reason" type="textarea" :rows="2" :label="__('Would you tell us why? (optional)')" :hint="__('It helps us do better. It is not required.')" />
                    <button type="submit" class="rounded-md border border-[var(--danger)] px-4 py-2 text-sm font-semibold text-[var(--danger)]">{{ __('Stop the gift') }}</button>
                </form>
            </details>
        @endunless
    </section>
@endforeach
