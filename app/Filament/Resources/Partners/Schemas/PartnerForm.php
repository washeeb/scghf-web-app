<?php

declare(strict_types=1);

namespace App\Filament\Resources\Partners\Schemas;

use App\Filament\Support\MediaPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PartnerForm
{
    /** @return array<string, string> */
    public static function partnerTypes(): array
    {
        return [
            'church' => __('Church or parish'),
            'company' => __('Company'),
            'ngo' => __('NGO'),
            'institution' => __('School or hospital'),
            'government' => __('Government body'),
            'individual' => __('Individual'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The partner'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')
                        ->label(__('Name'))
                        ->required()
                        ->maxLength(191)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                    TextInput::make('slug')
                        ->label(__('Address'))
                        ->required()
                        ->maxLength(191)
                        ->unique(ignoreRecord: true),
                ]),

                Grid::make(2)->schema([
                    Select::make('partner_type')
                        ->label(__('Kind'))
                        ->options(static::partnerTypes())
                        ->required(),

                    TextInput::make('website_url')
                        ->label(__('Website'))
                        ->url()
                        ->maxLength(500)
                        ->helperText(__('Opens in a new tab.')),
                ]),

                Textarea::make('description')
                    ->label(__('What they do with us'))
                    ->rows(3),
            ]),

            Section::make(__('Logo'))->schema([
                MediaPicker::image('logo_id')
                    ->label(__('Logo'))
                    ->helperText(__('Shown in the partners strip. A transparent PNG reads best against both themes.')),
            ]),

            Section::make(__('The partnership'))->schema([
                Grid::make(2)->schema([
                    DatePicker::make('partnership_started_on')
                        ->label(__('Started')),

                    /*
                     * An ended partnership is recorded rather than deleted.
                     * "Who have we worked with?" is a question a funder asks,
                     * and a partner removed from the list because the work
                     * finished is a piece of the foundation's history gone.
                     */
                    DatePicker::make('partnership_ended_on')
                        ->label(__('Ended'))
                        ->helperText(__('Leave empty while it is ongoing. A past partner stays on record; untick "show on the site" to take them off the page.')),
                ]),

                Grid::make(3)->schema([
                    Toggle::make('is_published')
                        ->label(__('Show on the site'))
                        ->default(true),

                    Toggle::make('is_featured')
                        ->label(__('Feature')),

                    TextInput::make('sort_order')
                        ->label(__('Order'))
                        ->numeric()
                        ->default(0),
                ]),
            ]),
        ]);
    }
}
