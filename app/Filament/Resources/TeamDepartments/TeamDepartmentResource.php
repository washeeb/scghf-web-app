<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeamDepartments;

use App\Filament\Resources\TeamDepartments\Pages\CreateTeamDepartment;
use App\Filament\Resources\TeamDepartments\Pages\EditTeamDepartment;
use App\Filament\Resources\TeamDepartments\Pages\ListTeamDepartments;
use App\Filament\Resources\TeamDepartments\Schemas\TeamDepartmentForm;
use App\Filament\Resources\TeamDepartments\Tables\TeamDepartmentsTable;
use App\Models\TeamDepartment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TeamDepartmentResource extends Resource
{
    protected static ?string $model = TeamDepartment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 71;

    protected static ?string $modelLabel = 'Team department';

    protected static ?string $pluralModelLabel = 'Team departments';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TeamDepartmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeamDepartmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamDepartments::route('/'),
            'create' => CreateTeamDepartment::route('/create'),
            'edit' => EditTeamDepartment::route('/{record}/edit'),
        ];
    }
}
