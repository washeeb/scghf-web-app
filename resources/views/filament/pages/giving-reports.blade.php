{{--
    The finance reports.

    Tables, not charts: a chart is a script and a library, and the question a
    trustee asks — "how much came in for the borehole in August?" — is a
    number in a cell. Every table can be read aloud.
--}}
@php
    $reports = $this->reports();
    $summary = $reports->summary();
    $recurring = $reports->recurring();
    $acquisition = $reports->acquisition();
    $reconciliation = $reports->reconciliation();
    $card = 'fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 text-sm text-gray-950 dark:text-white';
@endphp

<x-filament-panels::page>
    <div class="{{ $card }} flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500" for="from">{{ __('From') }}</label>
            <input id="from" type="date" wire:model.live="from" class="fi-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500" for="until">{{ __('Until') }}</label>
            <input id="until" type="date" wire:model.live="until" class="fi-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500" for="granularity">{{ __('Group by') }}</label>
            <select id="granularity" wire:model.live="granularity" class="fi-input rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                <option value="day">{{ __('Day') }}</option>
                <option value="week">{{ __('Week') }}</option>
                <option value="month">{{ __('Month') }}</option>
            </select>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach (['month' => __('This month'), 'last_month' => __('Last month'), 'week' => __('Last 7 days'), 'year' => __('This year')] as $range => $label)
                <button type="button" wire:click="setRange('{{ $range }}')" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-white/10 dark:text-white">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [__('Raised'), $summary['raised']->format(), __(':gifts gifts from :donors donors', ['gifts' => $summary['gifts'], 'donors' => $summary['donors']])],
            [__('Net of gateway fees'), $summary['net']->format(), __('Fees :fees', ['fees' => $summary['fees']->format()])],
            [__('Average gift'), $summary['average']->format(), __('Refunded :refunded', ['refunded' => $summary['refunded']->format()])],
            [__('Regular giving'), $recurring['monthly_value']->format().' / '.__('month'), __(':active active · :failing failing · :paused paused', $recurring)],
        ] as [$label, $value, $note])
            <div class="{{ $card }}">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([
            [__('By :period', ['period' => $granularity]), $reports->byPeriod($granularity)->map(fn ($r) => [$r['period'], $r['raised']->format(), $r['gifts']])],
            [__('By appeal'), $reports->byCause()->map(fn ($r) => [$r['label'], $r['raised']->format(), $r['gifts']])],
            [__('By channel'), $reports->byChannel()->map(fn ($r) => [$r['label'], $r['raised']->format(), $r['gifts']])],
            [__('By region of the work'), $reports->byRegion()->map(fn ($r) => [$r['label'], $r['raised']->format(), $r['gifts']])],
            [__('By source'), $reports->bySource()->map(fn ($r) => [$r['label'], $r['raised']->format(), $r['gifts']])],
        ] as [$heading, $rows])
            <div class="{{ $card }} overflow-x-auto">
                <h2 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ $heading }}</h2>
                @if ($rows->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Nothing in this period.') }}</p>
                @else
                    <table class="w-full">
                        <thead><tr><th class="{{ $th }}">{{ __('Group') }}</th><th class="{{ $th }} text-right">{{ __('Raised') }}</th><th class="{{ $th }} text-right">{{ __('Gifts') }}</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($rows as [$label, $raised, $gifts])
                                <tr><td class="{{ $td }}">{{ $label }}</td><td class="{{ $td }} text-right">{{ $raised }}</td><td class="{{ $td }} text-right">{{ $gifts }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach

        <div class="{{ $card }}">
            <h2 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ __('Donors and regular giving') }}</h2>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-gray-500">{{ __('New donors in the period') }}</dt><dd class="text-right text-gray-950 dark:text-white">{{ $acquisition['new'] }}</dd>
                <dt class="text-gray-500">{{ __('Returning donors') }}</dt><dd class="text-right text-gray-950 dark:text-white">{{ $acquisition['returning'] }}</dd>
                <dt class="text-gray-500">{{ __('Regular gifts started') }}</dt><dd class="text-right text-gray-950 dark:text-white">{{ $recurring['started_in_period'] }}</dd>
                <dt class="text-gray-500">{{ __('Regular gifts stopped') }}</dt><dd class="text-right text-gray-950 dark:text-white">{{ $recurring['cancelled_in_period'] }}</dd>
                <dt class="text-gray-500">{{ __('Retention of earlier regular donors') }}</dt><dd class="text-right text-gray-950 dark:text-white">{{ $recurring['retention'] === null ? '—' : $recurring['retention'].'%' }}</dd>
            </dl>
        </div>
    </div>

    <div class="{{ $card }}">
        <h2 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ __('Reconciliation') }}</h2>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
            <dt class="text-gray-500">{{ __('Settled, not yet reconciled') }}</dt><dd class="text-gray-950 dark:text-white">{{ $reconciliation['unreconciled'] }} · {{ $reconciliation['unreconciled_amount']->format() }}</dd>
            <dt class="text-gray-500">{{ __('Reconciled') }}</dt><dd class="text-gray-950 dark:text-white">{{ $reconciliation['reconciled'] }}</dd>
            <dt class="text-gray-500">{{ __('Needs review') }}</dt><dd @class(['font-semibold', 'text-red-600' => $reconciliation['needs_review'] > 0, 'text-gray-950 dark:text-white' => $reconciliation['needs_review'] === 0])>{{ $reconciliation['needs_review'] }}</dd>
        </dl>
        <p class="mt-3 text-xs text-gray-500">{{ __('Match each settled payment to the Paystack payout report or the bank statement and mark it reconciled on the donation. "Needs review" is a payment whose amount or currency did not match what was expected; it is never completed automatically.') }}</p>

        @if ($this->reconciliationRun)
            <div class="mt-4 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                <p class="font-medium text-gray-950 dark:text-white">{{ __('Last run, just now') }}: {{ $this->reconciliationRun['window'] ?? '' }}</p>
                <ul class="mt-1 list-disc pl-5 text-gray-700 dark:text-gray-300">
                    @foreach ($this->reconciliationRun['detail'] ?? [] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                    @if (empty($this->reconciliationRun['detail']))
                        <li>{{ __('Nothing needed a person.') }}</li>
                    @endif
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
