<?php

declare(strict_types=1);

namespace App\Filament\Resources\Announcements\Schemas;

use App\Filament\Support\MediaPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * A banner, an announcement bar, or a popup.
 *
 * ── One table, three placements ─────────────────────────────────────────────
 *
 * They differ only in where they render. Three near-identical tables would mean
 * three admin screens for what staff think of as one job — "put a notice about
 * the harvest appeal on the site".
 *
 * ── The end date is the point ───────────────────────────────────────────────
 *
 * An announcement with no expiry is one somebody has to remember to take down,
 * and nobody ever does. That is how a foundation's website ends up advertising
 * last December's carol service in March — visible to every donor, invisible to
 * the staff who stopped reading their own header months ago.
 *
 * So the form pushes towards setting one, and the list says plainly which
 * notices are live, which are scheduled, and which have quietly gone stale.
 *
 * ── A popup that returns on every visit loses the donor ─────────────────────
 *
 * `dismiss_days` is how long a dismissal sticks. The default of thirty days is
 * deliberate: a popup a visitor has already closed, reappearing on the next
 * page, is the single most reliable way to make somebody leave a site they came
 * to in order to give money.
 */
class AnnouncementForm
{
    /** @return array<string, string> */
    public static function placements(): array
    {
        return [
            'announcement_bar' => __('A bar across the top of every page'),
            'banner' => __('A banner inside the page'),
            'popup' => __('A popup'),
        ];
    }

    /** @return array<string, string> */
    public static function styles(): array
    {
        return [
            'info' => __('Information'),
            'success' => __('Good news'),
            'warning' => __('Caution'),
            'urgent' => __('Urgent'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('What it says'))->schema([
                TextInput::make('title')
                    ->label(__('Message'))
                    ->required()
                    ->maxLength(191)
                    ->helperText(__('Short. On a phone the bar is one line.')),

                Textarea::make('body')
                    ->label(__('More detail'))
                    ->rows(3)
                    ->helperText(__('Optional, and only shown for a banner or a popup. The bar shows the message alone.'))
                    ->visible(fn (Get $get): bool => $get('placement') !== 'announcement_bar'),

                Grid::make(2)->schema([
                    TextInput::make('cta_label')
                        ->label(__('Button text'))
                        ->maxLength(64)
                        ->helperText(__('"Give now", "Read more". Leave both this and the link empty for a notice with no button.')),

                    TextInput::make('cta_url')
                        ->label(__('Button link'))
                        ->url()
                        ->maxLength(500)
                        // Both halves or neither: a URL with no label is a link
                        // with nothing to click, and a label with no URL is
                        // text pretending to be one.
                        ->required(fn (Get $get): bool => filled($get('cta_label')))
                        ->helperText(__('Where the button goes.')),
                ]),
            ]),

            Section::make(__('Where and how it appears'))->schema([
                Grid::make(2)->schema([
                    Select::make('placement')
                        ->label(__('Placement'))
                        ->options(static::placements())
                        ->default('announcement_bar')
                        ->required()
                        ->live(),

                    Select::make('style')
                        ->label(__('Tone'))
                        ->options(static::styles())
                        ->default('info')
                        ->required()
                        ->helperText(__('Sets the colour. "Urgent" is loud on purpose — keep it for things that are.')),
                ]),

                MediaPicker::image('image_id')
                    ->label(__('Image'))
                    ->visible(fn (Get $get): bool => $get('placement') !== 'announcement_bar')
                    ->helperText(__('Optional, and only for a banner or popup.')),

                TagsInput::make('show_on_paths')
                    ->label(__('Only on these pages'))
                    ->placeholder(__('/donate'))
                    ->helperText(__('Leave empty to show it everywhere. End a path with * to cover a whole section — /projects* covers the list and every project.')),

                Grid::make(2)->schema([
                    Toggle::make('is_dismissible')
                        ->label(__('Visitors can close it'))
                        ->default(true)
                        ->live(),

                    TextInput::make('dismiss_days')
                        ->label(__('Stays closed for'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(365)
                        ->default(30)
                        ->suffix(__('days'))
                        ->visible(fn (Get $get): bool => (bool) $get('is_dismissible'))
                        ->helperText(__('A popup that comes back on the next page is the fastest way to lose somebody who came here to give.')),
                ]),
            ]),

            Section::make(__('When'))->schema([
                Grid::make(2)->schema([
                    DateTimePicker::make('starts_at')
                        ->label(__('From'))
                        ->seconds(false)
                        ->helperText(__('Leave empty to start as soon as it is switched on.')),

                    DateTimePicker::make('ends_at')
                        ->label(__('Until'))
                        ->seconds(false)
                        ->after('starts_at')
                        ->helperText(__('Set this. A notice with no end date is one somebody has to remember to remove, and nobody does.')),
                ]),

                Grid::make(2)->schema([
                    Toggle::make('is_active')
                        ->label(__('Switched on'))
                        ->helperText(__('Off until you are ready. It still only shows inside the dates above.')),

                    TextInput::make('sort_order')
                        ->label(__('Order'))
                        ->numeric()
                        ->default(0)
                        ->helperText(__('When two are live at once, the lower number wins the bar.')),
                ]),
            ]),
        ]);
    }
}
