<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserType;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Staff, and couriers — public accounts that hold the Courier role,
            // managed from here because the office creates them here.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('type', UserType::Staff)->orWhereHas('roles', fn (Builder $r) => $r->where('name', 'Courier')))
                ->with('roles'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->description(fn (User $record): string => (string) $record->email),
                TextColumn::make('roles.name')->label(__('Roles'))->badge(),
                IconColumn::make('two_factor')
                    ->label(__('2FA'))
                    ->boolean()
                    ->state(fn (User $record): bool => $record->hasTwoFactorEnabled()),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
                TextColumn::make('suspended_at')
                    ->label(__('Suspended'))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (): string => __('Suspended'))
                    ->placeholder(''),
            ])
            ->filters([
                SelectFilter::make('roles')->label(__('Role'))->relationship('roles', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
