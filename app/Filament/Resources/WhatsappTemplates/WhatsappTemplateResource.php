<?php

declare(strict_types=1);

namespace App\Filament\Resources\WhatsappTemplates;

use App\Filament\Resources\WhatsappTemplates\Pages\EditWhatsappTemplate;
use App\Filament\Resources\WhatsappTemplates\Pages\ListWhatsappTemplates;
use App\Models\WhatsappTemplate;
use App\Support\Features;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Communications → WhatsApp templates (Wave 2).
 *
 * Meta owns the words; this screen holds the mapping: Meta's name and
 * language for each of our keys, and whether Meta has approved it. The
 * body shown here is a copy for the log and the preview — the text to
 * submit to Meta, with our named placeholders where their numbered ones
 * go, in the order listed. Nothing sends until "Approved by Meta" is
 * ticked, and nothing sends at all while FEATURE_WHATSAPP is off.
 */
class WhatsappTemplateResource extends Resource
{
    protected static ?string $model = WhatsappTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Communications';

    protected static ?int $navigationSort = 25;

    protected static ?string $modelLabel = 'WhatsApp template';

    protected static ?string $pluralModelLabel = 'WhatsApp templates';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The template'))->columns(2)->schema([
                Placeholder::make('key')->label(__('Key'))->content(fn (?WhatsappTemplate $record): string => (string) $record?->key),
                Placeholder::make('category')->label(__('Category'))->content(fn (?WhatsappTemplate $record): string => ucfirst((string) $record?->category)),
                TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
                Placeholder::make('variables')->label(__('Placeholders, in Meta’s order'))
                    ->content(fn (?WhatsappTemplate $record): HtmlString => new HtmlString(implode(' ', array_map(
                        fn (int $i, string $v): string => sprintf('<code>{{%d}}</code> = %s', $i + 1, e($v)),
                        array_keys((array) $record?->variables), (array) $record?->variables,
                    )))),
                Textarea::make('body')->label(__('What the approved template says'))->rows(4)->columnSpanFull()
                    ->helperText(__('For the log and the preview only — Meta holds the real text. Submit this wording to Meta with {{1}}, {{2}} … where the names are.')),
            ]),
            Section::make(__('Meta'))->columns(3)->schema([
                TextInput::make('meta_name')->label(__('Template name in Meta'))->maxLength(191)
                    ->helperText(__('Exactly as it appears in Business Manager → Message templates.')),
                TextInput::make('language')->label(__('Language code'))->default('en')->maxLength(16)->required(),
                Toggle::make('is_approved')->label(__('Approved by Meta'))
                    ->helperText(__('Tick once Business Manager shows it as Approved. Nothing sends before.')),
                Toggle::make('is_active')->label(__('In use'))->default(true),
            ]),
            Section::make(__('Status'))->schema([
                Placeholder::make('channel')->label(__('The channel'))->content(fn (): string => app(Features::class)->enabled('whatsapp')
                    ? __('On — FEATURE_WHATSAPP is set; approved templates are sent through the :driver driver.', ['driver' => (string) config('communications.whatsapp.driver', 'log')])
                    : __('Off — FEATURE_WHATSAPP is not set. Templates can be prepared; nothing is sent and the opt-in box is not shown.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Template'))->description(fn (WhatsappTemplate $record): string => (string) $record->key),
                TextColumn::make('category')->label(__('Category'))->badge(),
                TextColumn::make('meta_name')->label(__('Meta name'))->fontFamily('mono')->placeholder(__('Not set')),
                TextColumn::make('language')->label(__('Language')),
                IconColumn::make('is_approved')->label(__('Approved'))->boolean(),
                IconColumn::make('is_active')->label(__('In use'))->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([]);
    }

    public static function canCreate(): bool
    {
        // Keys are code: a template the application never sends is noise.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsappTemplates::route('/'),
            'edit' => EditWhatsappTemplate::route('/{record}/edit'),
        ];
    }
}
