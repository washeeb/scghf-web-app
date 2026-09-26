<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Schemas;

use App\Filament\Support\MoneyField;
use App\Models\Cause;
use App\Payments\OfflineDonationService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Recording a gift that did not come through the website.
 *
 * Cash at an event, a cheque, a bank transfer, goods. Goes through
 * `OfflineDonationService` — the same allocation, counting and receipting as
 * an online gift, plus a transaction row marked `offline` so reconciliation
 * knows not to ask the gateway about it. A cheque needs its number; a gift
 * cannot have arrived in the future.
 */
class DonationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The gift'))->columns(3)->schema([
                MoneyField::make('amount')->label(__('Amount'))->required(),

                Select::make('offline_method')
                    ->label(__('How it arrived'))
                    ->options([
                        OfflineDonationService::METHOD_CASH => __('Cash'),
                        OfflineDonationService::METHOD_BANK_TRANSFER => __('Bank transfer'),
                        OfflineDonationService::METHOD_CHEQUE => __('Cheque'),
                        OfflineDonationService::METHOD_IN_KIND => __('Goods (valued)'),
                    ])
                    ->default(OfflineDonationService::METHOD_CASH)
                    ->required()
                    ->live(),

                DatePicker::make('received_on')
                    ->label(__('Received on'))
                    ->default(now())
                    ->maxDate(now())
                    ->required(),

                TextInput::make('offline_reference')
                    ->label(fn (Get $get): string => $get('offline_method') === OfflineDonationService::METHOD_CHEQUE ? __('Cheque number') : __('Bank or paying-in reference'))
                    ->maxLength(191)
                    ->required(fn (Get $get): bool => $get('offline_method') === OfflineDonationService::METHOD_CHEQUE)
                    ->helperText(__('What Finance matches against the statement.')),

                Select::make('cause_id')
                    ->label(__('Appeal'))
                    ->options(fn (): array => Cause::query()->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->helperText(__('Leave empty for the General Fund.')),

                Toggle::make('is_anonymous')->label(__('Anonymous on the site')),
            ]),

            Section::make(__('The donor'))->columns(3)->schema([
                TextInput::make('donor_name')->label(__('Name'))->required()->maxLength(191),
                TextInput::make('donor_email')->label(__('Email'))->type('email')->maxLength(191)
                    ->helperText(__('The acknowledgement goes here. Leave empty for a genuinely anonymous cash gift.')),
                TextInput::make('donor_phone')->label(__('Phone'))->type('tel')->maxLength(20),
            ]),

            Grid::make(2)->schema([
                Toggle::make('acknowledge')
                    ->label(__('Issue and email the acknowledgement now'))
                    ->default(true)
                    ->helperText(__('Off for a bulk entry you will acknowledge afterwards.')),

                Textarea::make('notes')->label(__('Notes'))->rows(2),
            ]),
        ]);
    }
}
