<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts;

use App\Filament\Resources\Payouts\Pages\CreatePayout;
use App\Filament\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Resources\Payouts\Pages\ViewPayout;
use App\Filament\Resources\Payouts\Schemas\PayoutForm;
use App\Filament\Resources\Payouts\Schemas\PayoutInfolist;
use App\Filament\Resources\Payouts\Tables\PayoutsTable;
use App\Models\Payout;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Money leaving the foundation — the expenditure log, as a screen.
 *
 * The model, the permissions (`payouts.request`, `.approve`,
 * `.mark_paid`) and the two-person rule have existed since Phase 7 with
 * no screen (found while building grants in Wave 2, whose "spend against
 * the award" reads these rows). Now: raise a draft, submit it, a second
 * person approves, whoever pays marks it paid **with evidence**. No edit
 * page after the draft and no delete — the ledger is append-only. A
 * payout is attributed to a division, project or cause (the model
 * insists) and optionally charged to a grant and a beneficiary case.
 */
class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 45;

    protected static ?string $modelLabel = 'Payout';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return PayoutForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PayoutInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayoutsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayouts::route('/'),
            'create' => CreatePayout::route('/create'),
            'view' => ViewPayout::route('/{record}'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->with(['division', 'project', 'cause', 'grant', 'beneficiary', 'requester', 'approver', 'evidence']);
    }
}
