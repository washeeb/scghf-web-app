{{--
    Site health.

    A plain list rather than a table: every row has a different shape of answer,
    and the advice under a failing check is a sentence, not a cell.

    The failing checks are NOT sorted to the top. The order is the order of the
    stack — environment, cron, queue, storage, backups, payments — and a person
    reading down it learns how the parts fit together. Reordering by severity
    would put "SMS credits low" above "the scheduler has stopped", which is
    backwards: the second is why the first will not fix itself.
--}}
<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <ul role="list" class="divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($this->checks() as $check)
                <li class="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                            {{ $check->label }}
                        </p>

                        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                            {{ $check->value }}
                        </p>

                        @if ($check->advice)
                            {{-- The sentence that makes the status actionable.
                                 A foundation administrator told "Queue:
                                 warning" and nothing else has been told
                                 nothing. --}}
                            <p class="mt-2 max-w-3xl text-sm text-gray-700 dark:text-gray-300">
                                {{ $check->advice }}
                            </p>
                        @endif
                    </div>

                    <div class="shrink-0">
                        <x-filament::badge :color="$check->colour()">
                            {{ $check->statusLabel() }}
                        </x-filament::badge>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</x-filament-panels::page>
