<?php

declare(strict_types=1);

namespace App\Filament\Resources\Documents\Schemas;

use App\Filament\Support\MediaPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A downloadable document — annual report, policy, financial statement.
 *
 * ── This is the transparency page ───────────────────────────────────────────
 *
 * A Ghanaian non-profit asking the public for money is expected to publish its
 * accounts, its safeguarding policy and its annual report. A donor deciding
 * whether to trust a payment form looks for exactly those, and their absence is
 * what a scam site has in common with a real one that never got round to it.
 *
 * ── `requires_auth` is a real gate, not a label ─────────────────────────────
 *
 * Some documents are internal — a staff handbook, an unredacted incident
 * policy. One marked as needing sign-in is served through an authorised
 * controller rather than a public storage URL, so "restricted" means the file
 * cannot be fetched by anyone who guesses the address.
 */
class DocumentForm
{
    /** @return array<string, string> */
    public static function documentTypes(): array
    {
        return [
            'annual_report' => __('Annual report'),
            'financial' => __('Financial statement'),
            'policy' => __('Policy'),
            'form' => __('Form'),
            'brochure' => __('Brochure'),
            'other' => __('Other'),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The document'))->schema([
                Grid::make(2)->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
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

                Textarea::make('description')
                    ->label(__('What it is'))
                    ->rows(2)
                    ->helperText(__('One line, shown next to the download link.')),

                MediaPicker::document('media_id')
                    ->label(__('File'))
                    ->required(),
            ]),

            Section::make(__('Filing'))->schema([
                Grid::make(2)->schema([
                    Select::make('document_type')
                        ->label(__('Kind'))
                        ->options(static::documentTypes())
                        ->default('other')
                        ->required(),

                    TextInput::make('year')
                        ->label(__('Year it covers'))
                        ->numeric()
                        ->minValue(2000)
                        ->maxValue((int) now()->addYear()->format('Y'))
                        ->helperText(__('Used to group reports by year on the transparency page.')),
                ]),

                Grid::make(2)->schema([
                    Toggle::make('is_published')
                        ->label(__('Show on the site')),

                    Toggle::make('requires_auth')
                        ->label(__('Sign-in required'))
                        ->helperText(__('For internal documents. The file is served through a check rather than a public address, so it cannot be fetched by guessing the link.')),
                ]),

                TextInput::make('sort_order')
                    ->label(__('Order'))
                    ->numeric()
                    ->default(0),
            ]),
        ]);
    }
}
