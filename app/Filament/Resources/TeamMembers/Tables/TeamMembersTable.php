<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamMembers\Tables;

use App\Filament\Resources\TeamMembers\Schemas\TeamMemberForm;
use App\Models\TeamMember;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class TeamMembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->description(fn (TeamMember $record): string => (string) $record->role_title),

                TextColumn::make('member_type')
                    ->label(__('Group'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => TeamMemberForm::memberTypes()[$state] ?? (string) $state),

                TextColumn::make('department.name')
                    ->label(__('Department'))
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('is_trustee')
                    ->label(__('Trustee'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('left_on')
                    ->label(__('Left'))
                    ->date('M Y')
                    ->placeholder(__('Still with us'))
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_published')
                    ->label(__('Shown'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('member_type')
                    ->label(__('Group'))
                    ->options(TeamMemberForm::memberTypes()),

                SelectFilter::make('team_department_id')
                    ->label(__('Department'))
                    ->relationship('department', 'name'),

                TernaryFilter::make('is_trustee')->label(__('Trustees')),
                TernaryFilter::make('is_published')->label(__('Shown on the site')),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
