<?php

declare(strict_types=1);

namespace App\Filament\Resources\FocusAreas;

use App\Filament\Resources\FocusAreas\Pages\CreateFocusArea;
use App\Filament\Resources\FocusAreas\Pages\EditFocusArea;
use App\Filament\Resources\FocusAreas\Pages\ListFocusAreas;
use App\Filament\Resources\FocusAreas\Schemas\FocusAreaForm;
use App\Filament\Resources\FocusAreas\Tables\FocusAreasTable;
use App\Models\FocusArea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class FocusAreaResource extends Resource
{
    protected static ?string $model = FocusArea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Programmes';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Area of work';

    protected static ?string $pluralModelLabel = 'Areas of work';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return FocusAreaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FocusAreasTable::configure($table);
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
            'index' => ListFocusAreas::route('/'),
            'create' => CreateFocusArea::route('/create'),
            'edit' => EditFocusArea::route('/{record}/edit'),
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
