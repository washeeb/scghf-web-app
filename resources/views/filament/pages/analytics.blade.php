{{--
    Site analytics: tables, not charts, for the same reasons as the finance
    reports. Every number here has a row in this database behind it.
--}}
@php
    $reports = $this->reports();
    $traffic = $reports->traffic();
    $conversions = $reports->conversions();
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
        <div class="flex flex-wrap gap-2">
            @foreach (['week' => __('Last 7 days'), 'default' => __('Last 30 days'), 'month' => __('This month'), 'quarter' => __('Last 90 days'), 'year' => __('This year')] as $range => $label)
                <button type="button" wire:click="setRange('{{ $range }}')" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-white/10 dark:text-white">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [__('Page views'), number_format($traffic['views']), __(':n a day over :days days', ['n' => $traffic['per_day'], 'days' => $traffic['days']])],
            [__('Visits'), number_format($traffic['sessions']), __('Distinct sessions; nobody is identified')],
            [__('Donations'), number_format($conversions['donations']['count']), $conversions['donations']['value']->format()],
            [__('Shop orders'), number_format($conversions['orders']['count']), $conversions['orders']['value']->format()],
        ] as [$label, $value, $note])
            <div class="{{ $card }}">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
            </div>
        @endforeach
    </div>

    <div class="{{ $card }} overflow-x-auto">
        <h2 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ __('Conversions') }}</h2>
        <table class="w-full">
            <thead><tr><th class="{{ $th }}">{{ __('What') }}</th><th class="{{ $th }} text-right">{{ __('How many') }}</th><th class="{{ $th }} text-right">{{ __('Value') }}</th></tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($conversions as $row)
                    <tr><td class="{{ $td }}">{{ $row['label'] }}</td><td class="{{ $td }} text-right tabular-nums">{{ number_format($row['count']) }}</td><td class="{{ $td }} text-right tabular-nums">{{ $row['value']?->format() ?? '—' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="{{ $card }} overflow-x-auto">
        <h2 class="mb-1 text-sm font-semibold text-gray-950 dark:text-white">{{ __('Income by campaign') }}</h2>
        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('From the utm_source and utm_campaign on the link that brought the visit. Add ?utm_source=whatsapp&utm_campaign=harvest to a link you share and its gifts show up here.') }}</p>
        @php($attribution = $reports->attribution())
        @if ($attribution->isEmpty())
            <p class="text-sm text-gray-500">{{ __('Nothing in this period.') }}</p>
        @else
            <table class="w-full">
                <thead><tr><th class="{{ $th }}">{{ __('Source') }}</th><th class="{{ $th }}">{{ __('Campaign') }}</th><th class="{{ $th }} text-right">{{ __('Gifts') }}</th><th class="{{ $th }} text-right">{{ __('Donated') }}</th><th class="{{ $th }} text-right">{{ __('Orders') }}</th><th class="{{ $th }} text-right">{{ __('Ordered') }}</th></tr></thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($attribution as $row)
                        <tr>
                            <td class="{{ $td }}">{{ $row['source'] }}</td>
                            <td class="{{ $td }}">{{ $row['campaign'] }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ number_format($row['donations']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $row['donated']->format() }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ number_format($row['orders']) }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $row['ordered']->format() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([
            [__('By day'), $reports->byDay()->map(fn ($r) => [$r['day'], number_format($r['views']), number_format($r['sessions'])]), [__('Day'), __('Views'), __('Visits')]],
            [__('Most-read pages'), $reports->topPages()->map(fn ($r) => [$r->value, number_format($r->views), '']), [__('Path'), __('Views'), '']],
            [__('Where visits came from'), $reports->referrers()->map(fn ($r) => [$r->value, number_format($r->views), '']), [__('Site'), __('Views'), '']],
            [__('Devices'), $reports->devices()->map(fn ($r) => [ucfirst($r->value), number_format($r->views), '']), [__('Device'), __('Views'), '']],
        ] as [$heading, $rows, $headers])
            <div class="{{ $card }} overflow-x-auto">
                <h2 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ $heading }}</h2>
                @if ($rows->isEmpty())
                    <p class="text-sm text-gray-500">{{ __('Nothing in this period.') }}</p>
                @else
                    <table class="w-full">
                        <thead><tr>@foreach ($headers as $h)<th class="{{ $th }} {{ $loop->first ? '' : 'text-right' }}">{{ $h }}</th>@endforeach</tr></thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($rows as $cells)
                                <tr>@foreach ($cells as $cell)<td class="{{ $td }} {{ $loop->first ? '' : 'text-right tabular-nums' }}">{{ $cell }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
