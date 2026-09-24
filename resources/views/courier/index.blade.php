{{-- The courier's list: what is in their hands, then what they have finished. --}}
<x-courier.layout :title="setting('courier.list_title', __('Your deliveries'))">
    @if ($instructions = setting('courier.instructions'))
        <p class="rounded-[var(--radius-md)] bg-[var(--surface-sunken)] px-4 py-3 text-sm text-[var(--text-secondary)]">{{ $instructions }}</p>
    @endif

    <section>
        <h2 class="font-heading text-lg font-semibold text-[var(--text-primary)]">{{ setting('courier.active_heading', __('To deliver')) }}</h2>

        @if ($active->isEmpty())
            <p class="mt-3 rounded-[var(--radius-lg)] border border-dashed border-[var(--border-strong)] px-4 py-8 text-center text-[var(--text-muted)]">{{ setting('courier.empty_message', __('Nothing to deliver right now.')) }}</p>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($active as $delivery)
                    @php $order = $delivery->order; @endphp
                    <li>
                        <a href="{{ route('courier.show', $delivery) }}" class="block rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow-sm)] hover:border-[var(--brand-primary)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-[var(--text-primary)]">{{ $order->delivery_name ?: $order->customer_name }}</p>
                                    <p class="mt-0.5 truncate text-sm text-[var(--text-muted)]">{{ collect([$order->delivery_area, $order->delivery_city])->filter()->implode(', ') ?: $order->delivery_address }}</p>
                                </div>
                                <span @class([
                                    'shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-[var(--brand-primary)] text-[var(--text-on-brand)]' => $delivery->status === App\Models\Delivery::STATUS_OUT_FOR_DELIVERY,
                                    'bg-[var(--surface-sunken)] text-[var(--text-primary)]' => in_array($delivery->status, [App\Models\Delivery::STATUS_ASSIGNED, App\Models\Delivery::STATUS_PICKED_UP], true),
                                    'bg-[var(--danger)] text-white' => $delivery->status === App\Models\Delivery::STATUS_FAILED,
                                ])>{{ $delivery->label() }}</span>
                            </div>
                            <p class="mt-2 text-xs text-[var(--text-muted)]">{{ $order->reference }} · {{ trans_choice('{1}:count item|[2,*]:count items', $order->items->count(), ['count' => $order->items->count()]) }}@if ($delivery->attempts > 0) · {{ trans_choice('{1}:count attempt|[2,*]:count attempts', $delivery->attempts, ['count' => $delivery->attempts]) }}@endif</p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($done->isNotEmpty())
        <section>
            <h2 class="font-heading text-lg font-semibold text-[var(--text-primary)]">{{ setting('courier.done_heading', __('Done')) }}</h2>
            <ul class="mt-3 divide-y divide-[var(--border)] rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)]">
                @foreach ($done as $delivery)
                    <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                        <span class="min-w-0 truncate">
                            <span class="font-medium text-[var(--text-primary)]">{{ $delivery->order->delivery_name ?: $delivery->order->customer_name }}</span>
                            <span class="text-[var(--text-muted)]"> · {{ $delivery->order->reference }}</span>
                        </span>
                        <span class="shrink-0 text-[var(--text-muted)]">{{ $delivery->label() }}@if ($delivery->delivered_at) · {{ $delivery->delivered_at->format('j M') }}@endif</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-courier.layout>
