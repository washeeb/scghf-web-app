<?php

declare(strict_types=1);

namespace App\Filament\Resources\Causes;

use App\Filament\Resources\Causes\Pages\CreateCause;
use App\Filament\Resources\Causes\Pages\EditCause;
use App\Filament\Resources\Causes\Pages\ListCauses;
use App\Filament\Resources\Causes\Schemas\CauseForm;
use App\Filament\Resources\Causes\Tables\CausesTable;
use App\Models\Cause;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CauseResource extends Resource
{
    protected static ?string $model = Cause::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Programmes';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Appeal';

    protected static ?string $pluralModelLabel = 'Appeals';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return CauseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CausesTable::configure($table);
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
            'index' => ListCauses::route('/'),
            'create' => CreateCause::route('/create'),
            'edit' => EditCause::route('/{record}/edit'),
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
