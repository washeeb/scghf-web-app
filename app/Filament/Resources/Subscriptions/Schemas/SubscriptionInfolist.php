<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class SubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The gift'))->columns(4)->schema([
                TextEntry::make('reference')->label(__('Reference'))->fontFamily('mono')->copyable(),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state->label()),
                TextEntry::make('amount')->label(__('Amount'))->state(fn (Subscription $r): string => $r->amount->format().' / '.$r->interval)->weight('bold'),
                TextEntry::make('cause.title')->label(__('Appeal'))->placeholder(__('General Fund')),
                TextEntry::make('started_on')->label(__('Since'))->date('j M Y'),
                TextEntry::make('next_charge_on')->label(__('Next charge'))->date('j M Y')->placeholder('—'),
                TextEntry::make('charge_count')->label(__('Charged'))->helperText(fn (Subscription $r): string => $r->totalCharged()->format()),
                TextEntry::make('failed_attempts')->label(__('Consecutive failures')),
                TextEntry::make('instrument')->label(__('Charged from'))
                    ->state(fn (Subscription $r): string => trim(($r->channel ?? '—').' '.($r->card_last4 ? '•••• '.$r->card_last4 : '')).($r->authorization_reusable ? '' : ' — '.__('NOT reusable; will not be charged'))),
                TextEntry::make('cancel_reason')->label(__('Ended or paused because'))->placeholder('—')->columnSpanFull(),
            ]),

            Section::make(__('The donor'))->columns(3)->schema([
                TextEntry::make('donor.name')->label(__('Name'))->url(fn (Subscription $r): ?string => $r->donor ? route('filament.admin.resources.donors.view', $r->donor) : null),
                TextEntry::make('donor.email')->label(__('Email'))->copyable()->placeholder('—'),
                TextEntry::make('donor.phone')->label(__('Phone'))->copyable()->placeholder('—'),
            ]),

            Section::make(__('Charges'))->schema([
                TextEntry::make('charges')->hiddenLabel()->state(fn (Subscription $r): HtmlString => new HtmlString(
                    '<ul class="text-sm">'.$r->charges()->latest('scheduled_on')->limit(36)->get()->map(fn (SubscriptionCharge $c): string => sprintf(
                        '<li class="py-1">%s · %s · <em>%s</em>%s</li>',
                        e($c->scheduled_on->format('j M Y')),
                        e($c->amount->format()),
                        e($c->status),
                        $c->failure_reason ? ' · <span class="text-red-600">'.e($c->failure_reason).'</span>' : '',
                    ))->implode('').'</ul>'
                )),
            ]),
        ]);
    }
}
