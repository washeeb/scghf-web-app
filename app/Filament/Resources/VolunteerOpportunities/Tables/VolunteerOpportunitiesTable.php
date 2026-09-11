<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerOpportunities\Tables;

use App\Models\VolunteerOpportunity;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VolunteerOpportunitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'applications as awaiting_count' => fn (Builder $q) => $q->whereIn('status', ['submitted', 'under_review']),
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('Role'))
                    ->searchable()
                    ->wrap()
                    ->description(fn (VolunteerOpportunity $record): string => collect([$record->location, $record->region])->filter()->implode(', ')),

                IconColumn::make('involves_vulnerable_contact')
                    ->label(__('Contact'))
                    ->boolean()
                    ->tooltip(__('Involves contact with children or vulnerable adults')),

                TextColumn::make('positions')
                    ->label(__('Places'))
                    ->alignEnd()
                    ->state(fn (VolunteerOpportunity $record): string => $record->positions_available === null
                        ? (string) $record->positions_filled
                        : $record->positions_filled.' / '.$record->positions_available),

                TextColumn::make('awaiting_count')
                    ->label(__('Awaiting review'))
                    ->alignEnd()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),

                TextColumn::make('closes_on')
                    ->label(__('Closes'))
                    ->date('j M Y')
                    ->placeholder(__('Open-ended'))
                    ->sortable(),

                IconColumn::make('is_open')
                    ->label(__('Open'))
                    ->boolean()
                    ->state(fn (VolunteerOpportunity $record): bool => $record->isOpen()),
            ])
            ->filters([
                TernaryFilter::make('is_published')->label(__('Shown on the site')),
                TernaryFilter::make('involves_vulnerable_contact')->label(__('Involves vulnerable contact')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
