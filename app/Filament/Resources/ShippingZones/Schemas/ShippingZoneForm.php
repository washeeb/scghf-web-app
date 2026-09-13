<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShippingZones\Schemas;

use App\Filament\Support\MoneyField;
use App\Models\ShippingZone;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A delivery zone and what it costs.
 *
 * ── Regions are a fixed list of sixteen ─────────────────────────────────────
 *
 * The regions of Ghana are a fact, and `ShippingZone::REGIONS` is the list. A
 * checkbox list rather than free text, because "Greater Accra" and "Gt Accra"
 * are two zones to a lookup and one to a customer.
 *
 * ── Collection is a zone with no regions and no rate ────────────────────────
 *
 * One code path through the checkout. Its description is what the customer
 * reads beside "I will collect it" — the address, the hours, who to ask for.
 *
 * ── A zone with no active rate delivers nowhere ─────────────────────────────
 *
 * The zone can be switched on and still offer nothing until a rate is
 * recorded, because what delivery costs is a decision made with a courier,
 * and a number invented here would be a number nobody agreed to honour.
 */
class ShippingZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('name')
                    ->label(__('Zone'))
                    ->required()
                    ->maxLength(191)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')
                    ->label(__('Key'))
                    ->required()
                    ->maxLength(191)
                    ->unique(ignoreRecord: true),
            ]),

            Grid::make(2)->schema([
                Toggle::make('is_pickup')
                    ->label(__('This is collection, not delivery'))
                    ->live()
                    ->helperText(__('Free, with no regions. The description below is what the customer reads about where and when to collect.')),

                Toggle::make('is_active')
                    ->label(__('Offer it at checkout'))
                    ->helperText(__('A delivery zone also needs at least one rate switched on below.')),
            ]),

            Textarea::make('description')
                ->label(fn (Get $get): string => $get('is_pickup') ? __('Collection instructions') : __('Notes'))
                ->rows(2)
                ->helperText(fn (Get $get): string => $get('is_pickup')
                    ? __('Shown at checkout: the address, the hours, who to ask for.')
                    : __('Optional. For your own records.')),

            Grid::make(3)
                ->visible(fn (Get $get): bool => (bool) $get('is_pickup'))
                ->schema([
                    TextInput::make('pickup_address')->label(__('Collect from'))->maxLength(255)
                        ->helperText(__('The address, as a courier would need it. On the order page and in the confirmation.')),
                    TextInput::make('pickup_hours')->label(__('When'))->maxLength(191)
                        ->helperText(__('Mon–Fri 9–4, Saturdays by arrangement…')),
                    TextInput::make('pickup_phone')->label(__('Phone to call'))->type('tel')->maxLength(32),
                ]),

            CheckboxList::make('regions')
                ->label(__('Regions in this zone'))
                ->options(array_combine(ShippingZone::REGIONS, ShippingZone::REGIONS))
                ->columns(3)
                ->hidden(fn (Get $get): bool => (bool) $get('is_pickup'))
                ->helperText(__('A region in two active zones is served by whichever comes first in the order.')),

            Section::make(__('Rates'))
                ->description(__('The cheapest rate that fits the basket\'s weight is offered. A rate with no weight band fits every basket.'))
                ->hidden(fn (Get $get): bool => (bool) $get('is_pickup'))
                ->schema([
                    Repeater::make('rates')
                        ->label('')
                        ->relationship()
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->collapsible()
                        ->defaultItems(0)
                        ->addActionLabel(__('Add a rate'))
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('name')
                                    ->label(__('Rate'))
                                    ->required()
                                    ->maxLength(191)
                                    ->helperText(__('"Standard", "Express".')),

                                MoneyField::make('price')
                                    ->label(__('Charge'))
                                    ->required(),

                                MoneyField::make('free_above')
                                    ->label(__('Free for baskets over'))
                                    ->helperText(__('Optional. Compared with the basket subtotal, before delivery.')),
                            ]),

                            Grid::make(3)->schema([
                                TextInput::make('min_weight_grams')->label(__('From'))->suffix('g')->numeric()->minValue(0),
                                TextInput::make('max_weight_grams')->label(__('Up to'))->suffix('g')->numeric()->minValue(0),
                                TextInput::make('estimated_days')
                                    ->label(__('Takes'))
                                    ->maxLength(64)
                                    ->helperText(__('"2–3 days". Shown to the customer.')),
                            ]),

                            Toggle::make('is_active')->label(__('Offered'))->default(true),
                        ]),
                ]),

            TextInput::make('sort_order')->label(__('Order'))->numeric()->default(0),
        ]);
    }
}
