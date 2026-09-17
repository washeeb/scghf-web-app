<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsTemplates\Schemas;

use App\Communications\SampleVariables;
use App\Communications\SmsSegmenter;
use App\Communications\TemplateRenderer;
use App\Models\SmsTemplate;
use App\ValueObjects\Money;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Editing the words of a text message, with the meter running.
 *
 * The counter under the body updates as the editor types: characters,
 * encoding, segments, and the one character that pushed it into the
 * expensive encoding — because "GH₵" costs a segment and nobody would guess
 * that. The count is of the RENDERED body with sample values, since a
 * template of 150 characters with a name in it is 160 by the time it goes.
 */
class SmsTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('What it is'))->columns(3)->schema([
                TextEntry::make('key')->label(__('Key'))->fontFamily('mono'),
                TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
                TextEntry::make('category')->label(__('Category'))->badge(),
                TextEntry::make('description')->label(__('When it goes'))->columnSpanFull()->placeholder('—'),
            ]),

            Section::make(__('Variables'))->schema([
                TextEntry::make('variables')
                    ->hiddenLabel()
                    ->state(fn (?SmsTemplate $record): HtmlString => self::variableMenu($record)),
            ])->collapsible(),

            Section::make(__('The message'))->schema([
                Textarea::make('body')
                    ->label(__('Text'))
                    ->required()
                    ->rows(4)
                    ->live(debounce: 400)
                    ->extraInputAttributes(['class' => 'font-mono'])
                    ->helperText(__('Plain text. A variable in double braces: {{order_reference}}. The sender name is added by the network, not typed here.')),

                TextEntry::make('meter')
                    ->hiddenLabel()
                    ->state(fn (Get $get, ?SmsTemplate $record): HtmlString => self::meter((string) $get('body'), $record))
                    ->columnSpanFull(),

                Grid::make(3)->schema([
                    TextInput::make('sender_id')
                        ->label(__('Sender ID'))
                        ->maxLength(11)
                        ->helperText(__('Empty = :default. Must be registered with the networks; an unregistered one is silently dropped.', ['default' => (string) config('communications.sms.sender_id')])),
                    TextInput::make('max_segments')
                        ->label(__('Segment budget'))
                        ->numeric()->minValue(1)->maxValue(5)
                        ->helperText(__('A longer message is refused at save. Each segment is billed.')),
                    Toggle::make('is_active')
                        ->label(__('Sends'))
                        ->disabled(fn (?SmsTemplate $record): bool => (bool) $record?->is_locked)
                        ->helperText(fn (?SmsTemplate $record): string => $record?->is_locked ? __('Locked: the words can change, the sending cannot.') : __('Off: the log records that nothing went, and why.')),
                ]),
            ]),
        ]);
    }

    private static function meter(string $body, ?SmsTemplate $record): HtmlString
    {
        $segmenter = app(SmsSegmenter::class);
        $rendered = app(TemplateRenderer::class)->render($body, SampleVariables::for($record?->available_variables ?? []));
        $m = $segmenter->measure($rendered);
        $budget = (int) ($record?->max_segments ?? config('communications.sms.max_segments', 2));
        $over = $m['segments'] > $budget;
        $forcing = $segmenter->charactersForcingUcs2($rendered);

        $line = __(':chars characters · :encoding · :segments segment(s) · about :cost per message', [
            'chars' => $m['characters'],
            'encoding' => $m['encoding'],
            'segments' => $m['segments'],
            'cost' => Money::ofMinor($segmenter->estimatedCostMinor($rendered))->format(),
        ]);

        $warning = $forcing !== []
            ? '<br><span class="text-amber-600">'.e(__('":chars" forces the expensive encoding (70 characters per segment instead of 160).', ['chars' => implode('', $forcing)])).'</span>'
            : '';

        $overLine = $over
            ? '<br><span class="text-red-600 font-semibold">'.e(__('Over the budget of :budget segment(s) — it will be refused.', ['budget' => $budget])).'</span>'
            : '';

        return new HtmlString('<p class="text-sm '.($over ? 'text-red-600' : 'text-gray-500').'">'.e($line).$warning.$overLine.'</p>');
    }

    private static function variableMenu(?SmsTemplate $record): HtmlString
    {
        $declared = $record?->available_variables ?? [];
        $required = $record?->required_variables ?? [];

        $rows = collect($declared)->map(fn (string $name): string => sprintf(
            '<tr><td class="py-1 pr-4 font-mono text-sm">{{%s}}%s</td><td class="py-1 text-sm text-gray-500">%s</td></tr>',
            e($name),
            in_array($name, $required, true) ? ' <span class="text-xs text-red-600">'.e(__('required')).'</span>' : '',
            e(app(TemplateRenderer::class)->stringify(SampleVariables::value($name))),
        ));

        return new HtmlString('<table class="w-full">'.$rows->implode('').'</table><p class="mt-2 text-xs text-gray-500">'.e(__('{{site_name}} is available everywhere.')).'</p>');
    }
}
