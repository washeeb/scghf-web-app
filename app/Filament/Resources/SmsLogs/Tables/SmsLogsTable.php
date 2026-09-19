<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsLogs\Tables;

use App\Models\SmsLog;
use App\ValueObjects\Money;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The SMS send log, with a running total: this month's estimated cost is
 * in the heading, because it is the number the treasurer asks for.
 */
class SmsLogsTable
{
    public static function configure(Table $table): Table
    {
        $month = Money::ofMinor((int) SmsLog::query()->where('status', SmsLog::STATUS_SENT)->where('sent_at', '>=', now()->startOfMonth())->sum('estimated_cost_minor'));

        return $table
            ->heading(__('SMS log — about :cost this month', ['cost' => $month->format()]))
            ->defaultSort('queued_at', 'desc')
            ->columns([
                TextColumn::make('channel')->label(__('Channel'))->badge()->formatStateUsing(fn (?string $state): string => $state === SmsLog::CHANNEL_WHATSAPP ? 'WhatsApp' : 'SMS')->color(fn (?string $state): string => $state === SmsLog::CHANNEL_WHATSAPP ? 'success' : 'gray'),
                TextColumn::make('to_number')->label(__('To'))->searchable()->copyable()->fontFamily('mono'),
                TextColumn::make('network')->label(__('Network'))->badge()->color('gray')->placeholder('—')->toggleable(),
                TextColumn::make('template_key')->label(__('Template'))->fontFamily('mono')->searchable(),
                TextColumn::make('segments')->label(__('Seg.'))->alignEnd(),
                TextColumn::make('estimated_cost_minor')->label(__('Cost'))->alignEnd()->state(fn (SmsLog $r): string => Money::ofMinor((int) $r->estimated_cost_minor)->format()),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (string $state): string => match ($state) {
                    SmsLog::STATUS_SENT, SmsLog::STATUS_DELIVERED => 'success',
                    SmsLog::STATUS_QUEUED => 'warning',
                    default => 'danger',
                }),
                TextColumn::make('driver')->label(__('Via'))->toggleable(),
                TextColumn::make('error')->label(__('Error'))->limit(60)->wrap()->placeholder('—')->toggleable(),
                TextColumn::make('sent_at')->label(__('Sent'))->dateTime('j M Y, H:i')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')->label(__('Channel'))->options([SmsLog::CHANNEL_SMS => 'SMS', SmsLog::CHANNEL_WHATSAPP => 'WhatsApp']),
                SelectFilter::make('status')->label(__('Status'))->options([
                    SmsLog::STATUS_SENT => __('Sent'), SmsLog::STATUS_DELIVERED => __('Delivered'), SmsLog::STATUS_FAILED => __('Failed'),
                    SmsLog::STATUS_SUPPRESSED => __('Refused'), SmsLog::STATUS_QUEUED => __('Queued'),
                ]),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
