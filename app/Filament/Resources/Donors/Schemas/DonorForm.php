<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\Schemas;

use App\Models\Donor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Correcting a donor's record.
 *
 * Name, contact details, organisation, and the consent flags — with the
 * warning that switching consent ON here is not consent. Consent is something
 * the donor gives; a staff member ticking the box on their behalf is a record
 * of nothing. The flags can be switched off (a donor who phoned to say stop),
 * and the note field is where to write that they did.
 */
class DonorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Who they are'))->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(191),
                Select::make('donor_type')->label(__('Type'))->options([
                    Donor::TYPE_INDIVIDUAL => __('Individual'),
                    Donor::TYPE_ORGANISATION => __('Organisation'),
                ])->required(),
                TextInput::make('organisation_name')->label(__('Organisation'))->maxLength(191),
                TextInput::make('email')->label(__('Email'))->type('email')->maxLength(191),
                TextInput::make('phone')->label(__('Phone'))->type('tel')->maxLength(32),
                TextInput::make('address')->label(__('Address'))->maxLength(255),
                TextInput::make('city')->label(__('Town or city'))->maxLength(191),
            ]),

            Section::make(__('Consent'))
                ->description(__('Switching a consent ON here is not consent — only the donor can give it, on a form, and it is recorded with the wording they saw. Switch OFF when they ask you to, and say so in the notes.'))
                ->columns(3)
                ->schema([
                    Toggle::make('consent_email')->label(__('Email updates')),
                    Toggle::make('consent_sms')->label(__('SMS updates')),
                    Toggle::make('is_anonymous_by_default')->label(__('Anonymous by default')),
                ]),

            Grid::make(1)->schema([
                Select::make('tags')
                    ->label(__('Tags'))
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(96),
                    ])
                    ->helperText(__('How Finance groups donors — "church network", "gala 2026", "major donor". A tag is a filter on the donor list and an export.')),
                Textarea::make('notes')->label(__('Notes'))->rows(3),
            ]),
        ]);
    }
}
