<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProjectUpdates;

use App\Filament\Resources\ProjectUpdates\Pages\CreateProjectUpdate;
use App\Filament\Resources\ProjectUpdates\Pages\EditProjectUpdate;
use App\Filament\Resources\ProjectUpdates\Pages\ListProjectUpdates;
use App\Filament\Resources\ProjectUpdates\Schemas\ProjectUpdateForm;
use App\Filament\Resources\ProjectUpdates\Tables\ProjectUpdatesTable;
use App\Models\ProjectUpdate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class ProjectUpdateResource extends Resource
{
    protected static ?string $model = ProjectUpdate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Programmes';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'Project update';

    protected static ?string $pluralModelLabel = 'Project updates';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ProjectUpdateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectUpdatesTable::configure($table);
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
            'index' => ListProjectUpdates::route('/'),
            'create' => CreateProjectUpdate::route('/create'),
            'edit' => EditProjectUpdate::route('/{record}/edit'),
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
