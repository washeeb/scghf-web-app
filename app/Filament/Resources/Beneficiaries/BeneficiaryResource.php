<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries;

use App\Filament\Resources\Beneficiaries\Pages\CreateBeneficiary;
use App\Filament\Resources\Beneficiaries\Pages\EditBeneficiary;
use App\Filament\Resources\Beneficiaries\Pages\ListBeneficiaries;
use App\Filament\Resources\Beneficiaries\Pages\ViewBeneficiary;
use App\Filament\Resources\Beneficiaries\RelationManagers\ConsentsRelationManager;
use App\Filament\Resources\Beneficiaries\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Beneficiaries\RelationManagers\NotesRelationManager;
use App\Filament\Resources\Beneficiaries\RelationManagers\PayoutsRelationManager;
use App\Filament\Resources\Beneficiaries\Schemas\CaseSchema;
use App\Filament\Resources\Beneficiaries\Tables\BeneficiariesTable;
use App\Models\Beneficiary;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Beneficiary cases — Wave 2, from docs/DESIGN-BENEFICIARY-CASES.md.
 *
 * ── What is deliberately not here ───────────────────────────────────────────
 *
 * No delete action of any kind. Closing a case is what starts the
 * retention clock; destruction is the retention runner's, with its legal
 * holds and its audit row. No bulk actions: there is nothing to do to
 * forty children at once. No `withTrashed` — a closed case is closed, not
 * a deleted one. No public form: intake is a member of staff with the
 * applicant (§5.1).
 *
 * ── What every screen is made from ──────────────────────────────────────────
 *
 * `App\Beneficiaries\FieldMap` and `CaseAccess`. The form, the case page
 * and the export are generated from the map, and the matrix test walks
 * the same map, so a field cannot be on a screen it is not classified for.
 */
class BeneficiaryResource extends Resource
{
    protected static ?string $model = Beneficiary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Programmes';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Case';

    protected static ?string $pluralModelLabel = 'Beneficiary cases';

    protected static ?string $recordTitleAttribute = 'case_reference';

    public static function form(Schema $schema): Schema
    {
        $record = $schema->getRecord();

        return CaseSchema::form($schema, $record instanceof Beneficiary ? $record : null);
    }

    public static function infolist(Schema $schema): Schema
    {
        /** @var Beneficiary $record */
        $record = $schema->getRecord();

        return CaseSchema::infolist($schema, $record);
    }

    public static function table(Table $table): Table
    {
        return BeneficiariesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            NotesRelationManager::class,
            DocumentsRelationManager::class,
            ConsentsRelationManager::class,
            PayoutsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBeneficiaries::route('/'),
            'create' => CreateBeneficiary::route('/create'),
            'view' => ViewBeneficiary::route('/{record}'),
            'edit' => EditBeneficiary::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        // Every relation an entry draws, because lazy loading is off.
        return parent::getRecordRouteBindingEloquentQuery()
            ->with(['division', 'project', 'focusArea', 'caseWorker']);
    }

    public static function getGloballySearchableAttributes(): array
    {
        // The reference and the name — Tier A. Never the ID number: the
        // blind index is the only way to ask about that, and it is asked on
        // the intake form.
        return ['case_reference', 'full_name'];
    }
}
