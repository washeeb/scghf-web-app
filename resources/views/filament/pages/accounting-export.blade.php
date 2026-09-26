{{--
    A month of journal lines, and the proof they balance.

    The table is the CSV, so what the treasurer sees is what they import.
    Debits and credits are totalled at the foot; the difference is always
    zero because every entry is written as a balanced pair — if it ever is
    not, the page says so in red rather than letting a file go out.
--}}
@php
    $export = app(App\Finance\JournalExport::class);
    $lines = $this->lines();
    $totals = $export->totals($lines);
    $package = $this->package();
    $card = 'fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 text-sm text-gray-950 dark:text-white';
    $accounts = collect(App\Finance\JournalExport::ACCOUNTS)->keys()->map(fn (string $key): array => [$key, ...$export->account($key)]);
@endphp

<x-filament-panels::page>
    <div class="{{ $card }} flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-medium text-gray-500" for="month">{{ __('Month') }}</label>
            <select id="month" wire:model.live="month" class="fi-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                @foreach ($this->months() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('Format: :package — set under Settings → Accounting, with the account codes and names.', ['package' => App\Finance\JournalExport::PACKAGES[$package]]) }}
        </p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="{{ $card }}">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Debits') }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $totals['debits']->format() }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Credits') }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $totals['credits']->format() }}</p>
        </div>
        <div class="{{ $card }}">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Lines') }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($totals['lines']) }}</p>
            @if ($totals['difference']->isZero())
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Balanced.') }}</p>
            @else
                <p class="mt-1 text-xs font-semibold" style="color:#dc2626">{{ __('Out of balance by :amount — do not import; tell the developer.', ['amount' => $totals['difference']->format()]) }}</p>
            @endif
        </div>
    </div>

    <div class="{{ $card }} overflow-x-auto">
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Journal for :month', ['month' => $this->monthLabel()]) }}</h2>
        @if ($lines->isEmpty())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing settled, sold, refunded or paid out in this month.') }}</p>
        @else
            <table class="mt-3 w-full">
                <thead>
                    <tr>
                        <th class="{{ $th }}">{{ __('Date') }}</th>
                        <th class="{{ $th }}">{{ __('Reference') }}</th>
                        <th class="{{ $th }}">{{ __('Account') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Debit') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Credit') }}</th>
                        <th class="{{ $th }}">{{ __('Description') }}</th>
                        <th class="{{ $th }}">{{ __('Fund') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="{{ $td }} whitespace-nowrap">{{ $line['date'] }}</td>
                            <td class="{{ $td }} whitespace-nowrap font-mono text-xs">{{ $line['reference'] }}</td>
                            <td class="{{ $td }}">{{ $line['account_code'] }} · {{ $line['account_name'] }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $line['debit'] }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $line['credit'] }}</td>
                            <td class="{{ $td }}">{{ $line['description'] }}</td>
                            <td class="{{ $td }}">{{ $line['fund'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="{{ $card }}">
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Chart of accounts in use') }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Change a code or a name under Settings → Accounting to match the accountant’s chart. The names below are what the CSV carries.') }}</p>
        <table class="mt-3 w-full">
            <tbody>
                @foreach ($accounts as [$key, $code, $name])
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="{{ $td }} font-mono text-xs">{{ $code }}</td>
                        <td class="{{ $td }}">{{ $name }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
