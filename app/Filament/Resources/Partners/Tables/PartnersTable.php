<?php

declare(strict_types=1);

namespace App\Filament\Resources\Partners\Tables;

use App\Filament\Resources\Partners\Schemas\PartnerForm;
use App\Models\Partner;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PartnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Partner'))
                    ->searchable()
                    ->description(fn (Partner $record): string => PartnerForm::partnerTypes()[$record->partner_type]
                        ?? (string) $record->partner_type),

                TextColumn::make('partnership_started_on')
                    ->label(__('Since'))
                    ->date('M Y')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('partnership_ended_on')
                    ->label(__('Until'))
                    ->date('M Y')
                    // An open-ended partnership is the normal case, and "—"
                    // reads as missing data. "Ongoing" is the actual fact.
                    ->placeholder(__('Ongoing'))
                    ->toggleable(),

                IconColumn::make('is_published')
                    ->label(__('Shown'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('partner_type')
                    ->label(__('Kind'))
                    ->options(PartnerForm::partnerTypes()),

                TernaryFilter::make('is_published')->label(__('Shown on the site')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
