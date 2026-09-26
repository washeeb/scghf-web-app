{{--
    The shop reports. Goods, not gifts — the gifts are in Finance → Reports —
    and one line at the bottom that adds the two without counting anything
    twice.
--}}
@php
    $reports = $this->reports();
    $summary = $reports->summary();
    $stock = $reports->stockValuation();
    $funds = $reports->fundsRaised();
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
            <select id="granularity" wire:model.live="granularity" class="fi-input rounded-lg border-gray-300 py-2 pl-3 pr-8 text-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
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
            [__('Goods sold'), $summary['goods']->format(), __(':orders orders · average :average', ['orders' => $summary['orders'], 'average' => $summary['average']->format()])],
            [__('Net proceeds'), $summary['net']->format(), __('Goods + delivery − discounts − fees :fees', ['fees' => $summary['fees']->format()])],
            [__('Gifts through the shop'), $summary['gifts']->format(), __('Counted as giving, not here')],
            [__('Refunded'), $summary['refunded']->format(), __('Orders paid in this period and reversed')],
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
            [__('By :period', ['period' => $granularity]), $reports->byPeriod($granularity)->map(fn ($r) => [$r['period'], $r['goods']->format(), $r['orders']]), __('Orders')],
            [__('By product'), $reports->byProduct()->map(fn ($r) => [$r['label'], $r['goods']->format(), $r['quantity']]), __('Units')],
            [__('By category'), $reports->byCategory()->map(fn ($r) => [$r['label'], $r['goods']->format(), $r['quantity']]), __('Units')],
            [__('Best sellers, by units'), $reports->bestSellers()->map(fn ($r) => [$r['label'], $r['goods']->format(), $r['quantity']]), __('Units')],
            [__('Goods revenue by appeal'), $reports->byCause()->map(fn ($r) => [$r['label'], $r['goods']->format(), $r['quantity']]), __('Units')],
        ] as [$heading, $rows, $third])
            <div class="{{ $card }} overflow-x-auto">
                <h2 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ $heading }}</h2>
                @if ($rows->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Nothing in this period.') }}</p>
                @else
                    <table class="w-full">
                        <thead><tr><th class="{{ $th }}">{{ __('Group') }}</th><th class="{{ $th }} text-right">{{ __('Goods') }}</th><th class="{{ $th }} text-right">{{ $third }}</th></tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($rows as [$label, $goods, $count])
                                <tr><td class="{{ $td }}">{{ $label }}</td><td class="{{ $td }} text-right">{{ $goods }}</td><td class="{{ $td }} text-right">{{ $count }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach

        <div class="{{ $card }}">
            <h2 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ __('Stock on the shelf') }}</h2>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-gray-500">{{ __('Units') }}</dt><dd class="text-right text-gray-950 dark:text-white">{{ number_format($stock['units']) }}</dd>
                <dt class="text-gray-500">{{ __('Across') }}</dt><dd class="text-right text-gray-950 dark:text-white">{{ __(':n variants', ['n' => $stock['variants']]) }}</dd>
                <dt class="text-gray-500">{{ __('Value at selling price') }}</dt><dd class="text-right font-semibold text-gray-950 dark:text-white">{{ $stock['value']->format() }}</dd>
            </dl>
            <p class="mt-3 text-xs text-gray-500">{{ __('At what it would sell for, not what it cost — the shop does not record a cost price.') }}</p>
        </div>
    </div>

    <div class="{{ $card }}">
        <h2 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ __('Total funds raised in the period') }}</h2>
        <dl class="grid grid-cols-3 gap-4 text-sm">
            <div><dt class="text-gray-500">{{ __('Donations') }}</dt><dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ $funds['donations']->format() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Net shop proceeds') }}</dt><dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ $funds['shop']->format() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Together') }}</dt><dd class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $funds['total']->format() }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-gray-500">{{ __('Donations include gifts made through the shop — a sponsored meal, a round-up — which is why they are not in the shop figures as well.') }}</p>
    </div>
</x-filament-panels::page>
