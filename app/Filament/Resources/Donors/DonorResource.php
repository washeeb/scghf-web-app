<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors;

use App\Filament\Resources\Donors\Pages\EditDonor;
use App\Filament\Resources\Donors\Pages\ListDonors;
use App\Filament\Resources\Donors\Pages\ViewDonor;
use App\Filament\Resources\Donors\Schemas\DonorForm;
use App\Filament\Resources\Donors\Schemas\DonorInfolist;
use App\Filament\Resources\Donors\Tables\DonorsTable;
use App\Models\Donor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The people who give. Lifetime value, first and last gift, and the record
 * of what was sent to them. Personal details sit behind donors.view_pii on
 * the donations they made; the donor record itself is who they are.
 */
class DonorResource extends Resource
{
    protected static ?string $model = Donor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Donor';

    protected static ?string $pluralModelLabel = 'Donors';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DonorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DonorsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DonorInfolist::configure($schema);
    }

    public static function canCreate(): bool
    {
        // A donor record is created by a gift, never typed in.
        return false;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->with(['tags', 'user']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDonors::route('/'),
            'view' => ViewDonor::route('/{record}'),
            'edit' => EditDonor::route('/{record}/edit'),
        ];
    }
}
