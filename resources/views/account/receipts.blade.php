{{--
    Receipts, by tax year.

    One section per year of assessment with the year's total and — where an
    appeal held a Section 97 approval at the time — the deductible total,
    which is the figure a donor's return needs. Every receipt opens as the
    PDF that was emailed; the download route checks the signed-in owner.

    Amounts print through Money; nothing here formats pesewas by hand.
--}}
<x-site.account-layout :title="__('Receipts')" :user="$user">

    @if ($donor === null || $years->isEmpty())
        <div class="rounded-lg border border-[var(--border)] bg-[var(--surface)] p-6">
            <h2 class="font-semibold text-[var(--text-primary)]">{{ __('No receipts yet') }}</h2>
            <p class="mt-2 text-sm text-[var(--text-muted)]">
                {{ __('A receipt is issued for every completed gift and appears here, filed by year, ready to download for your tax return.') }}
            </p>
            @if ($unreceipted > 0)
                <p class="mt-2 text-sm text-[var(--text-muted)]">
                    {{ trans_choice('One gift is still being receipted.|:count gifts are still being receipted.', $unreceipted, ['count' => $unreceipted]) }}
                </p>
            @endif
        </div>
    @else
        @if ($unreceipted > 0)
            <p class="text-sm text-[var(--text-muted)]">
                {{ trans_choice('One gift is still being receipted and will appear here shortly.|:count gifts are still being receipted and will appear here shortly.', $unreceipted, ['count' => $unreceipted]) }}
            </p>
        @endif

        @foreach ($years as $year)
            <section class="space-y-3" aria-labelledby="receipts-{{ $year['year'] }}" data-receipt-year="{{ $year['year'] }}">
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                    <h2 id="receipts-{{ $year['year'] }}" class="text-lg font-semibold text-[var(--text-primary)]">{{ $year['year'] }}</h2>
                    <p class="text-sm text-[var(--text-muted)]">
                        {{ __('Given: :total', ['total' => $year['total']]) }}
                        @if (! $year['deductible']->isZero())
                            · {{ __('Deductible: :amount', ['amount' => $year['deductible']]) }}
                        @endif
                    </p>
                </div>

                {{-- `relative`, so the visually-hidden labels inside the table
                     (absolutely positioned) are contained by this scrolling
                     box rather than stretching the page sideways on a phone. --}}
                <div class="relative overflow-x-auto rounded-lg border border-[var(--border)]">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ __('Receipts for :year', ['year' => $year['year']]) }}</caption>
                        <thead class="bg-[var(--surface)] text-[var(--text-muted)]">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('Date') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('Receipt') }}</th>
                                <th scope="col" class="px-4 py-3 font-medium">{{ __('Towards') }}</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">{{ __('Amount') }}</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Download') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border)]">
                            @foreach ($year['receipts'] as $receipt)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3 text-[var(--text-primary)]">{{ $receipt->donated_on?->format('j M Y') ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-[var(--text-muted)]">
                                        {{ $receipt->receipt_number }}
                                        @if ($receipt->supportsTaxClaim())
                                            <span class="ml-1 rounded-full border border-[var(--border)] px-2 py-0.5 font-sans text-[0.7rem] text-[var(--text-muted)]">{{ __('Tax relief') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-[var(--text-muted)]">{{ $receipt->cause ?: ($receipt->donation?->cause?->title ?? __('General Fund')) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-[var(--text-primary)]">{{ $receipt->amountReceived() }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right">
                                        <a href="{{ route('receipts.download', $receipt) }}" class="font-medium text-[var(--brand-primary)] hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--focus-ring)]">
                                            {{ __('PDF') }}<span class="sr-only"> — {{ $receipt->receipt_number }}</span>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    @endif
</x-site.account-layout>
