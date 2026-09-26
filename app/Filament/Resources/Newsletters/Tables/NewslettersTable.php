<?php

declare(strict_types=1);

namespace App\Filament\Resources\Newsletters\Tables;

use App\Models\Newsletter;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NewslettersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('List'))->searchable()->description(fn (Newsletter $r): ?string => $r->description),
                TextColumn::make('topic')->label(__('Topic'))->badge()->color('gray'),
                TextColumn::make('cadence')->label(__('How often'))->placeholder('—'),
                TextColumn::make('subscribers')->label(__('Subscribers'))->alignEnd()
                    ->state(fn (Newsletter $r): int => $r->subscriberQuery()->count()),
                IconColumn::make('is_active')->label(__('Open'))->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }
}
