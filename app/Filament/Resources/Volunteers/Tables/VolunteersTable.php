<?php

declare(strict_types=1);

namespace App\Filament\Resources\Volunteers\Tables;

use App\Models\Volunteer;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VolunteersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('full_name')
            ->columns([
                TextColumn::make('full_name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('role')->label(__('Role'))->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('division.name')->label(__('Division'))->placeholder('—')->toggleable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        Volunteer::STATUS_ACTIVE => 'success',
                        Volunteer::STATUS_SUSPENDED => 'danger',
                        Volunteer::STATUS_INACTIVE => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('is_cleared')
                    ->label(__('Cleared'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state, Volunteer $record): string => match (true) {
                        ! $record->involves_vulnerable_contact => __('n/a'),
                        $record->clearanceHasLapsed() => __('Lapsed'),
                        $state => __('Yes'),
                        default => __('No'),
                    })
                    ->color(fn (bool $state, Volunteer $record): string => match (true) {
                        ! $record->involves_vulnerable_contact => 'gray',
                        $record->clearanceHasLapsed(), ! $state => 'danger',
                        default => 'success',
                    }),
                TextColumn::make('clearance_expires_on')->label(__('Clearance expires'))->date('j M Y')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('total_hours')->label(__('Hours'))->numeric()->sortable(),
                TextColumn::make('started_on')->label(__('Since'))->date('M Y')->sortable()->toggleable(),
                TextColumn::make('concern_raised_at')
                    ->label(__('Concern'))
                    ->formatStateUsing(fn (): string => __('OPEN'))
                    ->badge()
                    ->color('danger')
                    ->placeholder(''),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    Volunteer::STATUS_ACTIVE => __('Active'),
                    Volunteer::STATUS_INACTIVE => __('Inactive'),
                    Volunteer::STATUS_SUSPENDED => __('Suspended'),
                    Volunteer::STATUS_LEFT => __('Left'),
                ]),
                SelectFilter::make('division_id')->label(__('Division'))->relationship('division', 'name'),
                Filter::make('lapsing')
                    ->label(__('Clearance lapsing within 60 days'))
                    ->query(fn (Builder $query): Builder => $query->clearanceLapsing()),
                Filter::make('concern')
                    ->label(__('Open concern'))
                    ->query(fn (Builder $query): Builder => $query->withOpenConcern()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
