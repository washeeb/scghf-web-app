<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Enums\SubscriptionStatus;
use App\Filament\Support\ExportAction;
use App\Models\Subscription;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Standing gifts. Failing ones first, because they are the ones that need a
 * person — everything else charges itself.
 */
class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['donor', 'cause']))
            ->defaultSort('next_charge_on')
            ->columns([
                TextColumn::make('reference')->label(__('Reference'))->fontFamily('mono')->searchable(),
                TextColumn::make('donor.name')->label(__('Donor'))->searchable()->description(fn (Subscription $r): ?string => $r->donor?->email),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->state(fn (Subscription $r): string => $r->amount->format().' / '.$r->interval),
                TextColumn::make('cause.title')->label(__('Appeal'))->placeholder(__('General Fund'))->wrap(),
                TextColumn::make('next_charge_on')->label(__('Next'))->date('j M Y')->placeholder('—')->sortable(),
                TextColumn::make('charge_count')->label(__('Charged'))->alignEnd()->description(fn (Subscription $r): string => $r->totalCharged()->format()),
                TextColumn::make('failed_attempts')->label(__('Failures'))->alignEnd()->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $s): string => $s->label())
                    ->color(fn (SubscriptionStatus $s): string => match ($s) {
                        SubscriptionStatus::Active => 'success',
                        SubscriptionStatus::Failing => 'danger',
                        SubscriptionStatus::Paused => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(SubscriptionStatus::options()),
                Filter::make('not_reusable')
                    ->label(__('Authorization not reusable'))
                    ->query(fn (Builder $q) => $q->where('authorization_reusable', false)->where('status', 'active')),
                Filter::make('due')
                    ->label(__('Due this week'))
                    ->query(fn (Builder $q) => $q->where('status', 'active')->whereBetween('next_charge_on', [now()->toDateString(), now()->addWeek()->toDateString()])),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                ExportAction::make('report.generated', __('regular gifts'), [
                    'Reference' => 'reference',
                    'Donor' => fn (Subscription $r) => $r->donor?->name,
                    'Amount' => fn (Subscription $r) => $r->amount->format(),
                    'Interval' => 'interval',
                    'Appeal' => fn (Subscription $r) => $r->cause?->title,
                    'Status' => fn (Subscription $r) => $r->status->value,
                    'Started' => fn (Subscription $r) => $r->started_on?->format('Y-m-d'),
                    'Next' => fn (Subscription $r) => $r->next_charge_on?->format('Y-m-d'),
                    'Charged' => 'charge_count',
                    'Total' => fn (Subscription $r) => $r->totalCharged()->format(),
                ], ['donor', 'cause']),
            ]);
    }
}
