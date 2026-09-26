<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\RelationManagers;

use App\Beneficiaries\CaseAccess;
use App\Models\Beneficiary;
use App\Models\Payout;
use App\ValueObjects\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What has been paid against this case — read-only here. Payouts are
 * raised, approved and marked paid in Finance's own resource, where the
 * two-person rule lives; this table is the case's view of them.
 *
 * Shown to views B, F and U.
 */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Money paid';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        if ($user === null || ! $ownerRecord instanceof Beneficiary) {
            return false;
        }

        return array_intersect(['B', 'F', 'U'], app(CaseAccess::class)->views($user, $ownerRecord)) !== [];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->label(__('Reference'))->fontFamily('mono'),
                TextColumn::make('purpose')->label(__('For'))->wrap(),
                TextColumn::make('category')->label(__('Category'))->badge(),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn (mixed $state): string => $state instanceof Money ? $state->format() : '—')->alignEnd(),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (?string $state): string => match ($state) {
                    Payout::STATUS_PAID => 'success',
                    Payout::STATUS_REJECTED, Payout::STATUS_CANCELLED => 'danger',
                    Payout::STATUS_APPROVED => 'info',
                    default => 'gray',
                }),
                TextColumn::make('paid_at')->label(__('Paid'))->date('j M Y')->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
