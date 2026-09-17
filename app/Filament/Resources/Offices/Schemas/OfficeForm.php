<?php

declare(strict_types=1);

namespace App\Filament\Resources\Offices\Schemas;

use App\Models\Office;
use App\Models\ShippingZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OfficeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The office'))->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(191)->helperText(__('"Head office", "Tamale field office".')),
                Select::make('region')->label(__('Region'))->options(array_combine(ShippingZone::REGIONS, ShippingZone::REGIONS))->searchable()->nullable(),
                Textarea::make('address')->label(__('Street address'))->rows(2)->maxLength(500)->columnSpanFull(),
                TextInput::make('city')->label(__('Town or city'))->maxLength(191),
                TextInput::make('gps_address')->label(__('Ghana Post GPS'))->maxLength(32)->helperText(__('NT-0123-4567. Often the only address that finds a building.')),
            ]),

            Section::make(__('Reaching it'))->columns(3)->schema([
                TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(32),
                TextInput::make('whatsapp')->label(__('WhatsApp'))->tel()->maxLength(32)->helperText(__('Written any way; the link is built correctly.')),
                TextInput::make('email')->label(__('Email'))->email()->maxLength(191),
                TextInput::make('directions_url')->label(__('Directions link'))->url()->maxLength(500)->columnSpanFull()
                    ->helperText(__('Optional. A Google Maps share link, say. Left empty, the page links to a map search for the address.')),
            ]),

            Section::make(__('Hours'))
                ->description(__('Free text per day: "8:00–17:00", "8–12, then 2–5", "by appointment", "closed". Leave a day empty to leave it out.'))
                ->columns(2)
                ->schema(collect(Office::DAYS)->map(fn (string $day) => TextInput::make("hours.{$day}")
                    ->label(['mon' => __('Monday'), 'tue' => __('Tuesday'), 'wed' => __('Wednesday'), 'thu' => __('Thursday'), 'fri' => __('Friday'), 'sat' => __('Saturday'), 'sun' => __('Sunday')][$day])
                    ->maxLength(64))->all()),

            Section::make(__('Listing'))->columns(3)->schema([
                Toggle::make('is_primary')->label(__('Main office'))->helperText(__('Only one. The header and footer describe this one.')),
                Toggle::make('is_active')->label(__('Show on the contact page'))->default(true),
                TextInput::make('sort_order')->label(__('Order'))->numeric()->default(0),
                Textarea::make('notes')->label(__('Notes for visitors'))->rows(2)->columnSpanFull()->helperText(__('"Behind the Total filling station", "ask for Auntie Grace at the gate".')),
            ]),
        ]);
    }
}
