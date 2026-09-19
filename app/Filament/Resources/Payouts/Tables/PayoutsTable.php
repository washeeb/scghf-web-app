<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Tables;

use App\Filament\Resources\Payouts\Schemas\PayoutForm;
use App\Filament\Support\ExportAction;
use App\Models\Payout;
use App\ValueObjects\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payouts, those awaiting somebody first. No payee account numbers in the
 * list; no bulk actions; no delete.
 */
class PayoutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'cause', 'grant', 'requester']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->label(__('Reference'))->fontFamily('mono')->searchable(),
                TextColumn::make('payee_name')->label(__('Paid to'))->searchable()->description(fn (Payout $record): string => (string) $record->purpose)->wrap(),
                TextColumn::make('amount')->label(__('Amount'))->formatStateUsing(fn (mixed $state): string => $state instanceof Money ? $state->format() : '—')->alignEnd()->sortable(query: fn (Builder $q, string $direction): Builder => $q->orderBy('amount_minor', $direction)),
                TextColumn::make('category')->label(__('Category'))->badge()->formatStateUsing(fn (?string $state): string => PayoutForm::CATEGORIES[$state] ?? (string) $state),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (?string $state): string => match ($state) {
                    Payout::STATUS_PAID => 'success',
                    Payout::STATUS_REJECTED, Payout::STATUS_CANCELLED => 'danger',
                    Payout::STATUS_APPROVED => 'info',
                    Payout::STATUS_PENDING => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('charged')->label(__('Charged to'))->state(fn (Payout $record): string => implode(' · ', array_filter([$record->project?->title, $record->cause?->title, $record->grant?->title])))->wrap()->toggleable(),
                TextColumn::make('requester.name')->label(__('Requested by'))->placeholder('—')->toggleable(),
                TextColumn::make('paid_at')->label(__('Paid'))->date('j M Y')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    Payout::STATUS_DRAFT => 'Draft',
                    Payout::STATUS_PENDING => 'Awaiting approval',
                    Payout::STATUS_APPROVED => 'Approved, not paid',
                    Payout::STATUS_PAID => 'Paid',
                    Payout::STATUS_REJECTED => 'Rejected',
                    Payout::STATUS_CANCELLED => 'Cancelled',
                ]),
                SelectFilter::make('category')->label(__('Category'))->options(PayoutForm::CATEGORIES),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('payouts'), [
                    'Reference' => 'reference',
                    'Status' => 'status',
                    'Paid to' => 'payee_name',
                    'Amount' => fn (Payout $record): string => $record->amount?->format() ?? '',
                    'Category' => 'category',
                    'Purpose' => 'purpose',
                    'Project' => fn (Payout $record): string => (string) $record->project?->title,
                    'Appeal' => fn (Payout $record): string => (string) $record->cause?->title,
                    'Grant' => fn (Payout $record): string => (string) $record->grant?->title,
                    'Paid on' => fn (Payout $record): string => (string) $record->paid_at?->toDateString(),
                ], ['project', 'cause', 'grant']),
            ]);
    }
}
