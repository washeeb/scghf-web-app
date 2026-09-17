<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsBroadcasts\Schemas;

use App\Communications\SmsSegmenter;
use App\Models\SmsBroadcast;
use App\ValueObjects\Money;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Composing a broadcast, with the bill on the screen.
 *
 * The meter under the text says characters, encoding, segments and — for
 * the chosen audience — the estimated cost in cedis, before anybody has
 * asked for approval. A message over the segment budget is refused at save.
 */
class SmsBroadcastForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The text'))->schema([
                TextInput::make('title')->label(__('Working title'))->required()->maxLength(191)
                    ->helperText(__('For the list. Recipients never see it.')),
                Textarea::make('body')
                    ->label(__('Message'))
                    ->required()
                    ->rows(4)
                    ->live(debounce: 400)
                    ->extraInputAttributes(['class' => 'font-mono'])
                    ->helperText(__('Plain text. The sender name (:sender) is added by the network. Say who you are in the first line anyway; a text from an unknown name is deleted.', ['sender' => (string) config('communications.sms.sender_id')])),
                TextEntry::make('meter')
                    ->hiddenLabel()
                    ->state(fn (Get $get): HtmlString => self::meter((string) $get('body'), (string) $get('audience'), (string) $get('custom_numbers'))),
            ]),

            Section::make(__('Who'))->columns(2)->schema([
                Select::make('audience')
                    ->label(__('Send to'))
                    ->options([
                        SmsBroadcast::AUDIENCE_DONORS => __('Donors who asked for SMS updates'),
                        SmsBroadcast::AUDIENCE_CUSTOM => __('Numbers I paste in'),
                    ])
                    ->default(SmsBroadcast::AUDIENCE_DONORS)
                    ->required()
                    ->live()
                    ->helperText(__('Donors: everyone who ticked "send me updates by SMS" and has a number, minus the do-not-contact list. Pasted numbers: yours to have consent for; the do-not-contact list still applies.')),
                DateTimePicker::make('scheduled_for')
                    ->label(__('Send at'))
                    ->seconds(false)
                    ->minDate(now())
                    ->helperText(__('Optional. Marketing texts wait for quiet hours to end regardless.')),
                Textarea::make('custom_numbers')
                    ->label(__('Numbers'))
                    ->rows(6)
                    ->visible(fn (Get $get): bool => $get('audience') === SmsBroadcast::AUDIENCE_CUSTOM)
                    ->required(fn (Get $get): bool => $get('audience') === SmsBroadcast::AUDIENCE_CUSTOM)
                    ->live(debounce: 600)
                    ->columnSpanFull()
                    ->helperText(__('One per line, or separated by commas. 024 123 4567 and +233 24 123 4567 are the same number and are sent once.')),
            ]),
        ]);
    }

    private static function meter(string $body, string $audience, string $custom): HtmlString
    {
        $segmenter = app(SmsSegmenter::class);
        $m = $segmenter->measure($body);
        $budget = (int) config('communications.sms.max_segments', 2);

        $draft = new SmsBroadcast(['audience' => $audience ?: SmsBroadcast::AUDIENCE_DONORS, 'custom_numbers' => $custom]);
        $recipients = $body === '' ? 0 : $draft->recipients()->count();
        $cost = Money::ofMinor($segmenter->estimatedCostMinor($body, max(1, $recipients)));

        $line = __(':chars characters · :encoding · :segments segment(s) · :recipients recipients · about :cost', [
            'chars' => $m['characters'],
            'encoding' => $m['encoding'],
            'segments' => $m['segments'],
            'recipients' => $recipients,
            'cost' => $recipients > 0 ? $cost->format() : Money::zero()->format(),
        ]);

        $over = $m['segments'] > $budget
            ? '<br><span class="text-red-600 font-semibold">'.e(__('Over the budget of :budget segment(s) — it will be refused.', ['budget' => $budget])).'</span>'
            : '';

        $forcing = $segmenter->charactersForcingUcs2($body);
        $warning = $forcing !== []
            ? '<br><span class="text-amber-600">'.e(__('":chars" forces the expensive encoding (70 characters per segment instead of 160).', ['chars' => implode('', $forcing)])).'</span>'
            : '';

        return new HtmlString('<p class="text-sm text-gray-500">'.e($line).$warning.$over.'</p>');
    }
}
