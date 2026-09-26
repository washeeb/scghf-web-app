{{--
    The assistant, in three panels: whether it is on, what it has done this
    month, and what somebody in the office marked as wrong.

    Written for a trustee or an administrator, not a developer: no token
    counts, no model version in the headline, and every number that is an
    estimate says so where it is read rather than in a footnote.
--}}
<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Is it on, and what is it spending? --}}
        <div class="fi-section space-y-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 lg:col-span-1 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center gap-2">
                <span @class([
                    'inline-block size-2.5 rounded-full',
                    'bg-success-500' => $on,
                    'bg-gray-400' => ! $on,
                ])></span>
                <p class="font-semibold text-gray-950 dark:text-white">
                    {{ $on ? __('Answering chats') : __('Not answering') }}
                </p>
            </div>

            @unless ($on)
                <p class="text-sm text-gray-500">
                    @if (! $usable)
                        {{ __('No model is configured, so every chat goes to a person. Add an API key to .env and set AI_DRIVER.') }}
                    @elseif ($overBudget)
                        {{ __('This month\'s ceiling has been reached, so every chat is going to a person.') }}
                    @else
                        {{ __('Switched off under Site settings → AI assistant. Every chat goes to a person.') }}
                    @endif
                </p>
            @else
                <p class="text-sm text-gray-500">
                    {{ $whenStaffOnline
                        ? __('It answers every chat first, whether or not the office is open.')
                        : __('It answers only when nobody has the chat inbox open.') }}
                </p>
            @endunless

            <dl class="space-y-2 border-t border-gray-100 pt-4 text-sm dark:border-white/10">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">{{ __('Spent this month') }}</dt>
                    <dd class="font-semibold text-gray-950 dark:text-white">
                        {{ App\ValueObjects\Money::ofMinor($spentMinor)->format() }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">{{ __('Ceiling') }}</dt>
                    <dd>{{ $ceilingMinor > 0 ? App\ValueObjects\Money::ofMinor($ceilingMinor)->format() : __('none set') }}</dd>
                </div>
            </dl>

            @if ($ceilingMinor > 0)
                @php $share = min(100, (int) round($spentMinor / $ceilingMinor * 100)); @endphp
                <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                    <div @class(['h-full rounded-full', 'bg-danger-500' => $share >= 90, 'bg-warning-500' => $share >= 70 && $share < 90, 'bg-success-500' => $share < 70])
                         style="width: {{ $share }}%"></div>
                </div>
            @endif

            <p class="text-xs text-gray-500">
                {{ __('An estimate from the rates in the configuration, not the invoice. The bill comes from the model provider in dollars.') }}
            </p>
        </div>

        {{-- What it did. --}}
        <div class="fi-section space-y-4 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 lg:col-span-2 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('This month') }}</p>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ([
                    ['label' => __('Answered'), 'value' => $answered],
                    ['label' => __('Passed to a person'), 'value' => $handed],
                    ['label' => __('Could not answer'), 'value' => $failed],
                    ['label' => __('Typical reply'), 'value' => $medianMs > 0 ? round($medianMs / 1000, 1).'s' : '—'],
                ] as $stat)
                    <div>
                        <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $stat['value'] }}</p>
                        <p class="text-sm text-gray-500">{{ $stat['label'] }}</p>
                    </div>
                @endforeach
            </div>

            @if ($reasons->isNotEmpty())
                <div class="border-t border-gray-100 pt-4 dark:border-white/10">
                    <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Why a person was needed') }}</p>
                    <p class="mb-3 mt-1 text-sm text-gray-500">
                        {{-- The genuinely useful reading of this table. --}}
                        {{ __('A reason near the top every month is usually a page that needs writing, not a fault in the assistant.') }}
                    </p>
                    <ul class="space-y-1 text-sm">
                        @foreach ($reasons as $reason)
                            <li class="flex justify-between gap-4">
                                <span class="truncate text-gray-700 dark:text-gray-300">{{ $reason['reason'] }}</span>
                                <span class="shrink-0 font-semibold text-gray-950 dark:text-white">{{ $reason['total'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    {{-- What it got wrong. --}}
    <div class="fi-section mt-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <p class="text-xs uppercase tracking-wide text-gray-500">{{ __('Answers marked wrong') }}</p>

        @if ($flagged->isEmpty())
            <p class="mt-2 text-sm text-gray-500">
                {{ __('Nothing marked yet. In a chat, hover an answer from the assistant and press "This answer was wrong".') }}
            </p>
        @else
            <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($flagged as $item)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <p class="text-gray-950 dark:text-white">{{ $item->flag_note ?: __('No note left.') }}</p>
                            <p class="text-gray-500">
                                {{ $item->flagger?->name ?? __('Somebody') }} · {{ $item->flagged_at?->format('j M Y, H:i') }}
                            </p>
                        </div>
                        @if ($item->conversation)
                            <a
                                href="{{ App\Filament\Resources\ChatConversations\ChatConversationResource::getUrl('view', ['record' => $item->conversation]) }}"
                                class="shrink-0 text-primary-600 hover:underline"
                            >{{ __('Open the chat') }}</a>
                        @else
                            {{-- The conversation is gone: twelve-month retention
                                 deletes the words and keeps the cost record. --}}
                            <span class="shrink-0 text-gray-400">{{ __('the chat has been deleted') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
