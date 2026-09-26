<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants;

use App\Filament\Resources\Grants\Pages\CreateGrant;
use App\Filament\Resources\Grants\Pages\EditGrant;
use App\Filament\Resources\Grants\Pages\ListGrants;
use App\Filament\Resources\Grants\Pages\ViewGrant;
use App\Filament\Resources\Grants\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Grants\RelationManagers\ObligationsRelationManager;
use App\Filament\Resources\Grants\RelationManagers\PayoutsRelationManager;
use App\Filament\Resources\Grants\Schemas\GrantForm;
use App\Filament\Resources\Grants\Schemas\GrantInfolist;
use App\Filament\Resources\Grants\Tables\GrantsTable;
use App\Models\Grant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Grants — Wave 2 (1.6): the pipeline, the deadlines, the papers, and
 * what has been spent against each award from the payouts ledger.
 *
 * Kept to deadlines and documents on purpose. The risk the roadmap named
 * is a CRM nobody updates; a grant here has a status, a deadline, the
 * obligations the funder is owed, its files, and the money — no contact
 * history, no pipeline value forecasts.
 */
class GrantResource extends Resource
{
    protected static ?string $model = Grant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'Grant';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return GrantForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GrantInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GrantsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ObligationsRelationManager::class,
            DocumentsRelationManager::class,
            PayoutsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGrants::route('/'),
            'create' => CreateGrant::route('/create'),
            'view' => ViewGrant::route('/{record}'),
            'edit' => EditGrant::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->with(['funder', 'project', 'owner', 'division']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'funder_reference', 'funder.name'];
    }
}
