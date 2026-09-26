<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppressions;

use App\Filament\Resources\Suppressions\Pages\ListSuppressions;
use App\Filament\Resources\Suppressions\Schemas\SuppressionForm;
use App\Filament\Resources\Suppressions\Tables\SuppressionsTable;
use App\Models\Suppression;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Addresses and numbers the application will not write to, and why.
 * Bounces, complaints, STOP replies, erasure requests, and the ones staff
 * add by hand. Releasing one needs a person and a reason.
 */
class SuppressionResource extends Resource
{
    protected static ?string $model = Suppression::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Communications';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'Suppressed address';

    protected static ?string $pluralModelLabel = 'Do-not-contact list';

    protected static ?string $recordTitleAttribute = 'address';

    public static function form(Schema $schema): Schema
    {
        return SuppressionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppressionsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppressions::route('/'),
        ];
    }
}
