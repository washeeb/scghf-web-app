<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsBroadcasts\Tables;

use App\Models\SmsBroadcast;
use App\ValueObjects\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SmsBroadcastsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('approver')->withCount('logs'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Broadcast'))->searchable(['title', 'body'])
                    ->description(fn (SmsBroadcast $r): string => mb_strimwidth($r->body, 0, 80, '…')),
                TextColumn::make('audience')->label(__('To'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => $state === SmsBroadcast::AUDIENCE_CUSTOM ? __('pasted numbers') : __('donors (SMS)')),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (string $state): string => match ($state) {
                    SmsBroadcast::STATUS_QUEUED => 'success',
                    SmsBroadcast::STATUS_CANCELLED => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('recipient_count')->label(__('Recipients'))->alignEnd(),
                TextColumn::make('estimated_cost_minor')->label(__('Est. cost'))->alignEnd()
                    ->state(fn (SmsBroadcast $r): string => Money::ofMinor((int) $r->estimated_cost_minor)->format()),
                TextColumn::make('logs_count')->label(__('Sent'))->alignEnd(),
                TextColumn::make('scheduled_for')->label(__('Send at'))->dateTime('j M, H:i')->placeholder(__('At once'))->sortable(),
                TextColumn::make('approver.name')->label(__('Approved by'))->placeholder(__('Not yet'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    SmsBroadcast::STATUS_DRAFT => __('Draft'),
                    SmsBroadcast::STATUS_QUEUED => __('Queued / sent'),
                    SmsBroadcast::STATUS_CANCELLED => __('Cancelled'),
                ]),
            ])
            ->recordActions([EditAction::make()]);
    }
}
