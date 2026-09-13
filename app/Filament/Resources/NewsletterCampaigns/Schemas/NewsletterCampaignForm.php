<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsletterCampaigns\Schemas;

use App\Filament\Support\MediaPicker;
use App\Models\Cause;
use App\Models\Newsletter;
use App\Models\NewsletterCampaign;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Composing a campaign.
 *
 * ── Blocks, then HTML if you must ───────────────────────────────────────────
 *
 * Six blocks cover a newsletter: a heading, a paragraph, a button, a
 * picture, a rule, an appeal card drawn live from the appeal's own figures.
 * They compile to email-safe HTML on save. The HTML tab is for somebody who
 * knows what they are doing and is left alone when there are blocks.
 *
 * ── Nothing here sends ──────────────────────────────────────────────────────
 *
 * Sending is the page's actions: test, build the list, approve, schedule.
 * The form only says what the message is and when it should go.
 */
class NewsletterCampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The campaign'))->columns(3)->schema([
                TextInput::make('title')->label(__('Working title'))->required()->maxLength(191)
                    ->helperText(__('For the list of campaigns. Readers never see it.')),
                Select::make('newsletter_id')
                    ->label(__('List'))
                    ->relationship('newsletter', 'name', fn (EloquentBuilder $query) => $query->where('is_active', true))
                    ->required()
                    ->helperText(__('Who it goes to: everybody subscribed to this list\'s topic.')),
                DateTimePicker::make('scheduled_for')
                    ->label(__('Send at'))
                    ->seconds(false)
                    ->minDate(now())
                    ->helperText(__('Optional. Once approved, the cron sends it from this time in batches within the hourly allowance.')),
                TextInput::make('subject')->label(__('Subject'))->required()->maxLength(255)->columnSpan(2),
                TextInput::make('preheader')->label(__('Preview line'))->maxLength(191),
            ]),

            Tabs::make()->columnSpanFull()->tabs([
                Tabs\Tab::make(__('Compose'))->schema([
                    Builder::make('blocks')
                        ->label('')
                        ->addActionLabel(__('Add a block'))
                        ->collapsible()
                        ->blockNumbers(false)
                        ->blocks([
                            Block::make('heading')->label(__('Heading'))->icon('heroicon-o-h2')->schema([
                                TextInput::make('text')->label(__('Heading'))->required()->maxLength(191),
                            ]),
                            Block::make('paragraph')->label(__('Paragraph'))->icon('heroicon-o-bars-3-bottom-left')->schema([
                                Textarea::make('text')->label(__('Text'))->required()->rows(5)
                                    ->helperText(__('A blank line starts a new paragraph.')),
                            ]),
                            Block::make('button')->label(__('Button'))->icon('heroicon-o-cursor-arrow-rays')->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('label')->label(__('Says'))->required()->maxLength(60),
                                    TextInput::make('url')->label(__('Goes to'))->url()->required()->maxLength(500),
                                ]),
                            ]),
                            Block::make('image')->label(__('Picture'))->icon('heroicon-o-photo')->schema([
                                MediaPicker::image('media_id')->label(__('Image'))->required(),
                                Grid::make(2)->schema([
                                    TextInput::make('alt')->label(__('Describe it'))->maxLength(191),
                                    TextInput::make('caption')->label(__('Caption'))->maxLength(191),
                                ]),
                            ]),
                            Block::make('divider')->label(__('Rule'))->icon('heroicon-o-minus')->schema([]),
                            Block::make('appeal')->label(__('Appeal'))->icon('heroicon-o-heart')->schema([
                                Select::make('cause_id')
                                    ->label(__('Appeal'))
                                    ->options(fn (): array => Cause::query()->orderBy('title')->pluck('title', 'id')->all())
                                    ->searchable()
                                    ->required()
                                    ->helperText(__('Drawn with its title, one line, and how far it has got — the figures as they are when the campaign is SENT.')),
                            ]),
                        ]),
                ]),

                Tabs\Tab::make(__('HTML'))->schema([
                    TextEntry::make('html_note')
                        ->hiddenLabel()
                        ->state(fn (?NewsletterCampaign $record): string => ! empty($record?->blocks)
                            ? __('This campaign is composed from blocks; the HTML below is what they compiled to and is replaced on every save.')
                            : __('Write the message as HTML. Paragraphs, bold, links; the layout, logo and footer are added around it.')),
                    Textarea::make('body_html')
                        ->label(__('Body (HTML)'))
                        ->rows(18)
                        ->extraInputAttributes(['class' => 'font-mono text-sm'])
                        ->disabled(fn (?NewsletterCampaign $record): bool => ! empty($record?->blocks)),
                    Textarea::make('body_text')
                        ->label(__('Body (plain text)'))
                        ->rows(8)
                        ->extraInputAttributes(['class' => 'font-mono text-sm'])
                        ->disabled(fn (?NewsletterCampaign $record): bool => ! empty($record?->blocks)),
                ]),
            ]),
        ]);
    }
}
