<?php

declare(strict_types=1);

namespace App\Filament\Resources\CauseUpdates;

use App\Filament\Resources\CauseUpdates\Pages\CreateCauseUpdate;
use App\Filament\Resources\CauseUpdates\Pages\EditCauseUpdate;
use App\Filament\Resources\CauseUpdates\Pages\ListCauseUpdates;
use App\Filament\Resources\CauseUpdates\Schemas\CauseUpdateForm;
use App\Filament\Resources\CauseUpdates\Tables\CauseUpdatesTable;
use App\Models\CauseUpdate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CauseUpdateResource extends Resource
{
    protected static ?string $model = CauseUpdate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Programmes';

    protected static ?int $navigationSort = 50;

    protected static ?string $modelLabel = 'Appeal update';

    protected static ?string $pluralModelLabel = 'Appeal updates';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return CauseUpdateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CauseUpdatesTable::configure($table);
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
            'index' => ListCauseUpdates::route('/'),
            'create' => CreateCauseUpdate::route('/create'),
            'edit' => EditCauseUpdate::route('/{record}/edit'),
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
