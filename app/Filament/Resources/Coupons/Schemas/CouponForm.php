<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Schemas;

use App\Filament\Support\MoneyField;
use App\Models\Coupon;
use App\ValueObjects\Money;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * A discount code.
 *
 * ── The value is typed the way it reads ─────────────────────────────────────
 *
 * "10" for ten percent, "25.00" for twenty-five cedis off. It is stored as
 * basis points or pesewas — one integer column, because a coupon is only ever
 * one of the two — and the conversion happens here, in one place, so a
 * percentage typed into a field that thinks in pesewas cannot become a
 * discount of ten pesewas.
 *
 * ── A percentage has a ceiling ──────────────────────────────────────────────
 *
 * "20% off" on an unusually large order is a number nobody signed off. The
 * ceiling is optional, and the helper says why it exists.
 */
class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('code')
                    ->label(__('Code'))
                    ->required()
                    ->maxLength(32)
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->helperText(__('Letters and numbers. Saved in capitals; customers may type it either way.')),

                TextInput::make('description')
                    ->label(__('What it is for'))
                    ->maxLength(191)
                    ->helperText(__('"Church harvest 2026". For your records.')),
            ]),

            Grid::make(3)->schema([
                Select::make('discount_type')
                    ->label(__('Kind'))
                    ->options([
                        Coupon::TYPE_PERCENTAGE => __('A percentage off'),
                        Coupon::TYPE_FIXED => __('An amount off'),
                        Coupon::TYPE_FREE_SHIPPING => __('Free delivery'),
                    ])
                    ->default(Coupon::TYPE_PERCENTAGE)
                    ->required()
                    ->live(),

                TextInput::make('discount_value')
                    ->label(fn (Get $get): string => $get('discount_type') === Coupon::TYPE_FIXED ? __('Amount off') : __('Percent off'))
                    ->prefix(fn (Get $get): ?string => $get('discount_type') === Coupon::TYPE_FIXED ? 'GH₵' : null)
                    ->suffix(fn (Get $get): ?string => $get('discount_type') === Coupon::TYPE_PERCENTAGE ? '%' : null)
                    ->numeric()
                    ->minValue(0)
                    ->rule('decimal:0,2')
                    ->required(fn (Get $get): bool => $get('discount_type') !== Coupon::TYPE_FREE_SHIPPING)
                    ->hidden(fn (Get $get): bool => $get('discount_type') === Coupon::TYPE_FREE_SHIPPING)
                    ->maxValue(fn (Get $get): ?int => $get('discount_type') === Coupon::TYPE_PERCENTAGE ? 100 : null)
                    /*
                     * Stored as basis points for a percentage (1000 = 10%) and
                     * pesewas for an amount. Both directions of the conversion
                     * are here and nowhere else.
                     */
                    ->formatStateUsing(fn (mixed $state, Get $get): ?string => match (true) {
                        blank($state) => null,
                        $get('discount_type') === Coupon::TYPE_FIXED => Money::ofMinor((int) $state)->toMajorString(),
                        default => rtrim(rtrim(number_format(((int) $state) / 100, 2, '.', ''), '0'), '.'),
                    })
                    ->dehydrateStateUsing(fn (mixed $state, Get $get): int => match (true) {
                        blank($state) => 0,
                        $get('discount_type') === Coupon::TYPE_FIXED => Money::ofMajor((float) $state)->toMinor(),
                        default => (int) round(((float) $state) * 100),
                    }),

                MoneyField::make('maximum_discount')
                    ->label(__('Never more than'))
                    ->visible(fn (Get $get): bool => $get('discount_type') === Coupon::TYPE_PERCENTAGE)
                    ->helperText(__('Optional. A ceiling, so 20% of an unusually large order is not a number nobody signed off.')),
            ]),

            Section::make(__('Conditions'))->schema([
                Grid::make(3)->schema([
                    MoneyField::make('minimum_spend')
                        ->label(__('Basket of at least'))
                        ->helperText(__('Optional.')),

                    TextInput::make('usage_limit')
                        ->label(__('Uses in total'))
                        ->numeric()
                        ->minValue(1)
                        ->helperText(__('Empty for unlimited.')),

                    TextInput::make('usage_limit_per_customer')
                        ->label(__('Uses per customer'))
                        ->numeric()
                        ->minValue(1)
                        ->helperText(__('By email address. Empty for unlimited.')),
                ]),

                Grid::make(3)->schema([
                    DateTimePicker::make('starts_at')->label(__('From'))->seconds(false),
                    DateTimePicker::make('expires_at')->label(__('Until'))->seconds(false)->after('starts_at'),
                    Toggle::make('is_active')->label(__('Active'))->default(true),
                ]),
            ]),
        ]);
    }
}
