<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolunteerOpportunities;

use App\Filament\Resources\VolunteerOpportunities\Pages\CreateVolunteerOpportunity;
use App\Filament\Resources\VolunteerOpportunities\Pages\EditVolunteerOpportunity;
use App\Filament\Resources\VolunteerOpportunities\Pages\ListVolunteerOpportunities;
use App\Filament\Resources\VolunteerOpportunities\Schemas\VolunteerOpportunityForm;
use App\Filament\Resources\VolunteerOpportunities\Tables\VolunteerOpportunitiesTable;
use App\Models\VolunteerOpportunity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * The roles people can volunteer for.
 *
 * `involves_vulnerable_contact` is the flag the whole safeguarding module turns
 * on. It defaults to TRUE: for a foundation whose work is orphans and widows,
 * the safe assumption is contact, and the recruiter says otherwise
 * deliberately.
 */
class VolunteerOpportunityResource extends Resource
{
    protected static ?string $model = VolunteerOpportunity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Volunteer role';

    protected static ?string $pluralModelLabel = 'Volunteer roles';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return VolunteerOpportunityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VolunteerOpportunitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVolunteerOpportunities::route('/'),
            'create' => CreateVolunteerOpportunity::route('/create'),
            'edit' => EditVolunteerOpportunity::route('/{record}/edit'),
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
