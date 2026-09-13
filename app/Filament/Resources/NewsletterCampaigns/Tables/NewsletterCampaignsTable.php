<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsletterCampaigns\Tables;

use App\Models\NewsletterCampaign;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NewsletterCampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['newsletter', 'approver']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Campaign'))->searchable(['title', 'subject'])
                    ->description(fn (NewsletterCampaign $r): string => $r->subject),
                TextColumn::make('newsletter.name')->label(__('List')),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (string $state): string => match ($state) {
                    NewsletterCampaign::STATUS_SENT => 'success',
                    NewsletterCampaign::STATUS_SENDING, NewsletterCampaign::STATUS_BUILDING => 'info',
                    NewsletterCampaign::STATUS_SCHEDULED => 'warning',
                    NewsletterCampaign::STATUS_PAUSED, NewsletterCampaign::STATUS_FAILED => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('scheduled_for')->label(__('Send at'))->dateTime('j M Y, H:i')->placeholder('—')->sortable(),
                TextColumn::make('progress')->label(__('Progress'))
                    ->state(fn (NewsletterCampaign $r): string => $r->recipient_count > 0
                        ? __(':sent of :total', ['sent' => $r->sent_count, 'total' => $r->recipient_count]).($r->failed_count ? ' · '.$r->failed_count.' '.__('failed') : '')
                        : '—'),
                TextColumn::make('approver.name')->label(__('Approved by'))->placeholder(__('Not yet'))->toggleable(),
                TextColumn::make('created_at')->label(__('Created'))->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options([
                    NewsletterCampaign::STATUS_DRAFT => __('Draft'),
                    NewsletterCampaign::STATUS_SCHEDULED => __('Scheduled'),
                    NewsletterCampaign::STATUS_SENDING => __('Sending'),
                    NewsletterCampaign::STATUS_PAUSED => __('Paused'),
                    NewsletterCampaign::STATUS_SENT => __('Sent'),
                    NewsletterCampaign::STATUS_CANCELLED => __('Cancelled'),
                    NewsletterCampaign::STATUS_FAILED => __('Failed'),
                ]),
                SelectFilter::make('newsletter')->label(__('List'))->relationship('newsletter', 'name'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
