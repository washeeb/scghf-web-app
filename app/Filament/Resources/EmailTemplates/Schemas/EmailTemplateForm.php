<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailTemplates\Schemas;

use App\Communications\SampleVariables;
use App\Communications\TemplateRenderer;
use App\Models\EmailTemplate;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Editing the words of an email.
 *
 * ── The variables are a menu, not a memory test ─────────────────────────────
 *
 * Every placeholder the sending code provides is listed with a sample of
 * what it carries; one that the body uses and nobody declared is named at
 * save time rather than arriving blank in a donor's inbox.
 *
 * ── HTML as HTML ────────────────────────────────────────────────────────────
 *
 * A rich-text editor rewrites `{{amount}}` into paragraphs and entities the
 * renderer cannot see. The body is plain text with tags, which is what a
 * transactional email is: a few paragraphs, a strong tag, a link. The
 * preview shows the result inside the real layout.
 */
class EmailTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('What it is'))->columns(3)->schema([
                TextEntry::make('key')->label(__('Key'))->fontFamily('mono')
                    ->helperText(__('What the sending code asks for. It cannot change.')),
                TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
                TextEntry::make('category')->label(__('Category'))->badge()
                    ->helperText(__('Marketing must carry an unsubscribe link and respects quiet hours; transactional goes at once.')),
                TextEntry::make('description')->label(__('When it goes'))->columnSpanFull()->placeholder('—'),
            ]),

            Section::make(__('Variables'))->schema([
                TextEntry::make('variables')
                    ->hiddenLabel()
                    ->state(fn (?EmailTemplate $record): HtmlString => self::variableMenu($record)),
            ])->collapsible(),

            Section::make(__('The message'))->schema([
                TextInput::make('subject')->label(__('Subject'))->required()->maxLength(255),
                TextInput::make('preheader')->label(__('Preview line'))->maxLength(191)
                    ->helperText(__('Optional. What the inbox shows after the subject. Not shown in the message itself.')),
                Textarea::make('body_html')
                    ->label(__('Body (HTML)'))
                    ->required()
                    ->rows(16)
                    ->extraInputAttributes(['class' => 'font-mono text-sm'])
                    ->rule(fn (?EmailTemplate $record): \Closure => fn (string $attribute, mixed $value, \Closure $fail) => self::checkPlaceholders($record, (string) $value, $fail))
                    ->helperText(__('Paragraphs, bold, links. Put a variable in double braces: {{donor_name}}. The layout, logo and footer are added around this.')),
                Textarea::make('body_text')
                    ->label(__('Body (plain text)'))
                    ->rows(10)
                    ->extraInputAttributes(['class' => 'font-mono text-sm'])
                    ->rule(fn (?EmailTemplate $record): \Closure => fn (string $attribute, mixed $value, \Closure $fail) => self::checkPlaceholders($record, (string) $value, $fail))
                    ->helperText(__('What a reader with images and HTML off sees. Leave empty to send the HTML alone.')),
            ]),

            Section::make(__('Sending'))->columns(3)->collapsed()->schema([
                TextInput::make('from_name')->label(__('From name'))->maxLength(191)->helperText(__('Empty = the site default.')),
                TextInput::make('from_address')->label(__('From address'))->email()->maxLength(191)->helperText(__('Empty = the site default. Must be on a domain with SPF and DKIM set up.')),
                TextInput::make('reply_to')->label(__('Reply to'))->email()->maxLength(191),
                Grid::make(2)->columnSpanFull()->schema([
                    Toggle::make('is_active')
                        ->label(__('Sends'))
                        ->helperText(__('Off: the code still asks for it and the log records that nothing went, with this as the reason. A locked template cannot be switched off.'))
                        ->disabled(fn (?EmailTemplate $record): bool => (bool) $record?->is_locked),
                    TextEntry::make('is_locked')
                        ->label(__('Locked'))
                        ->state(fn (?EmailTemplate $record): string => $record?->is_locked
                            ? __('Yes — a receipt, a reset, a confirmation: the words can change, the sending cannot.')
                            : __('No')),
                ]),
            ]),
        ]);
    }

    private static function variableMenu(?EmailTemplate $record): HtmlString
    {
        $renderer = app(TemplateRenderer::class);
        $declared = $record?->available_variables ?? [];
        $required = $record?->required_variables ?? [];

        $rows = collect($declared)->map(function (string $name) use ($required): string {
            $sample = SampleVariables::value($name);
            $sample = $sample instanceof HtmlString ? __('(a list)') : app(TemplateRenderer::class)->stringify($sample);

            return sprintf(
                '<tr><td class="py-1 pr-4 font-mono text-sm">{{%s}}%s</td><td class="py-1 text-sm text-gray-500">%s</td></tr>',
                e($name),
                in_array($name, $required, true) ? ' <span class="text-xs text-red-600">'.e(__('required')).'</span>' : '',
                e($sample),
            );
        });

        $globals = collect($renderer->globals())->keys()->map(fn (string $name): string => '{{'.$name.'}}')->implode(', ');

        return new HtmlString(
            '<table class="w-full">'.$rows->implode('').'</table>'
            .'<p class="mt-2 text-xs text-gray-500">'.e(__('Available in every template: :globals', ['globals' => $globals])).'</p>'
        );
    }

    private static function checkPlaceholders(?EmailTemplate $record, string $body, \Closure $fail): void
    {
        if ($record === null) {
            return;
        }

        $renderer = app(TemplateRenderer::class);
        $declared = array_merge($record->available_variables ?? [], array_keys($renderer->globals()), array_keys((array) config('communications.templates.global_variables', [])));
        $unknown = $renderer->undeclared($body, $declared);

        if ($unknown !== []) {
            $fail(__('These variables are not provided for this message and would arrive blank: :names', [
                'names' => implode(', ', array_map(fn (string $n): string => '{{'.$n.'}}', $unknown)),
            ]));
        }
    }
}
