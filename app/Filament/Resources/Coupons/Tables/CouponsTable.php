<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Tables;

use App\Models\Coupon;
use App\ValueObjects\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->searchable()
                    ->fontFamily('mono')
                    ->description(fn (Coupon $record): ?string => $record->description),

                TextColumn::make('discount')
                    ->label(__('Discount'))
                    ->state(fn (Coupon $record): string => match ($record->discount_type) {
                        Coupon::TYPE_FIXED => Money::ofMinor((int) $record->discount_value)->format().' '.__('off'),
                        Coupon::TYPE_FREE_SHIPPING => __('Free delivery'),
                        default => rtrim(rtrim(number_format($record->discount_value / 100, 2, '.', ''), '0'), '.').'% '.__('off'),
                    }),

                TextColumn::make('times_used')
                    ->label(__('Used'))
                    ->alignEnd()
                    ->state(fn (Coupon $record): string => $record->usage_limit === null
                        ? (string) $record->times_used
                        : $record->times_used.' / '.$record->usage_limit),

                TextColumn::make('expires_at')
                    ->label(__('Until'))
                    ->dateTime('j M Y')
                    ->placeholder(__('No end'))
                    ->sortable(),

                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('Active')),
            ])
            ->recordActions([EditAction::make()]);
    }
}
