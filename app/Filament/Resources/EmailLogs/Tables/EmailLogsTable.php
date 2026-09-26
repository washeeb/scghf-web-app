<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailLogs\Tables;

use App\Models\EmailLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmailLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('queued_at', 'desc')
            ->columns([
                TextColumn::make('to_address')->label(__('To'))->searchable()->copyable()->description(fn (EmailLog $r): ?string => $r->to_name),
                TextColumn::make('subject')->label(__('Subject'))->searchable()->limit(60)->wrap(),
                TextColumn::make('template_key')->label(__('Template'))->fontFamily('mono')->searchable()->toggleable(),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (string $state): string => match ($state) {
                    EmailLog::STATUS_SENT, EmailLog::STATUS_DELIVERED => 'success',
                    EmailLog::STATUS_QUEUED, EmailLog::STATUS_SENDING => 'warning',
                    default => 'danger',
                }),
                TextColumn::make('opened_at')->label(__('Opened'))->dateTime('j M, H:i')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error')->label(__('Error'))->limit(80)->wrap()->placeholder('—')->toggleable(),
                TextColumn::make('sent_at')->label(__('Sent'))->dateTime('j M Y, H:i')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    EmailLog::STATUS_SENT => __('Sent'), EmailLog::STATUS_DELIVERED => __('Delivered'), EmailLog::STATUS_BOUNCED => __('Bounced'),
                    EmailLog::STATUS_SOFT_BOUNCED => __('Soft bounce'), EmailLog::STATUS_QUEUED => __('Queued'),
                ]),
                SelectFilter::make('category')->label(__('Category'))->options(['transactional' => __('Transactional'), 'marketing' => __('Marketing'), 'system' => __('System')]),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
