<?php

declare(strict_types=1);

namespace App\Filament\Resources\Grants\RelationManagers;

use App\Filament\Resources\Payouts\PayoutResource;
use App\Models\Payout;
use App\ValueObjects\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What has been charged to this grant — the ledger's rows, read-only.
 * A payout is raised under Finance → Payouts and given the grant there.
 */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Spend against the grant';

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
                TextColumn::make('payee_name')->label(__('Paid to')),
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
            ->headerActions([
                Action::make('raise')
                    ->label(__('Raise a payout'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => auth()->user()?->can('payouts.request') ?? false)
                    ->url(fn (): string => PayoutResource::getUrl('create', ['grant' => $this->getOwnerRecord()->getKey()])),
            ])
            ->recordActions([
                Action::make('view')->label(__('Open'))->url(fn (Payout $record): string => PayoutResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }
}
