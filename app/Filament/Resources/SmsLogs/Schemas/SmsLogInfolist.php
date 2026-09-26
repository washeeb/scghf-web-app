<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsLogs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SmsLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The text'))->columns(3)->schema([
                TextEntry::make('to_number')->label(__('To'))->copyable()->fontFamily('mono'),
                TextEntry::make('sender_id')->label(__('From')),
                TextEntry::make('template_key')->label(__('Template'))->fontFamily('mono'),
                TextEntry::make('body')->label(__('Message'))->columnSpanFull(),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('driver')->label(__('Via'))->placeholder('—'),
                TextEntry::make('provider_message_id')->label(__('Provider id'))->fontFamily('mono')->placeholder('—'),
                TextEntry::make('encoding')->label(__('Encoding')),
                TextEntry::make('segments')->label(__('Segments')),
                TextEntry::make('estimated_cost_minor')->label(__('Estimated cost'))->money('GHS', divideBy: 100),
                TextEntry::make('sent_at')->label(__('Sent'))->dateTime('j M Y, H:i:s')->placeholder('—'),
                TextEntry::make('delivered_at')->label(__('Delivered'))->dateTime('j M Y, H:i:s')->placeholder(__('No report')),
                TextEntry::make('provider_status')->label(__('Provider said'))->placeholder('—'),
                TextEntry::make('blocked_reason')->label(__('Refused because'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('error')->label(__('Error'))->color('danger')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }
}
