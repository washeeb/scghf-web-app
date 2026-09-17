<?php

declare(strict_types=1);

namespace App\Filament\Resources\Volunteers;

use App\Filament\Resources\Volunteers\Pages\ListVolunteers;
use App\Filament\Resources\Volunteers\Pages\ViewVolunteer;
use App\Filament\Resources\Volunteers\RelationManagers\HoursRelationManager;
use App\Filament\Resources\Volunteers\RelationManagers\ShiftsRelationManager;
use App\Filament\Resources\Volunteers\Schemas\VolunteerInfolist;
use App\Filament\Resources\Volunteers\Tables\VolunteersTable;
use App\Models\Volunteer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * The people who volunteer.
 *
 * A record here is only ever created by approving an application — the
 * safeguarding checks are what stand between the two — so there is no
 * create page. What happens afterwards is actions with names on them:
 * hours, shifts, a concern, a clearance lapsing, leaving.
 */
class VolunteerResource extends Resource
{
    protected static ?string $model = Volunteer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 25;

    protected static ?string $modelLabel = 'Volunteer';

    protected static ?string $pluralModelLabel = 'Volunteers';

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function infolist(Schema $schema): Schema
    {
        return VolunteerInfolist::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return VolunteersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ShiftsRelationManager::class,
            HoursRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVolunteers::route('/'),
            'view' => ViewVolunteer::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
