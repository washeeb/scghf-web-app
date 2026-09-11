<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerApplications;

use App\Filament\Resources\VolunteerApplications\Pages\ListVolunteerApplications;
use App\Filament\Resources\VolunteerApplications\Pages\ViewVolunteerApplication;
use App\Filament\Resources\VolunteerApplications\RelationManagers\ChecksRelationManager;
use App\Filament\Resources\VolunteerApplications\Schemas\VolunteerApplicationInfolist;
use App\Filament\Resources\VolunteerApplications\Tables\VolunteerApplicationsTable;
use App\Models\VolunteerApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * Applications to volunteer, and the safeguarding checks they wait on.
 *
 * There is no edit page. An application is what the applicant said; what the
 * foundation does with it is a set of recorded decisions - the checks, the
 * approval, the refusal - and each is an action with a name on it.
 */
class VolunteerApplicationResource extends Resource
{
    protected static ?string $model = VolunteerApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Application';

    protected static ?string $pluralModelLabel = 'Volunteer applications';

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function infolist(Schema $schema): Schema
    {
        return VolunteerApplicationInfolist::configure($schema);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return VolunteerApplicationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChecksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVolunteerApplications::route('/'),
            'view' => ViewVolunteerApplication::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
