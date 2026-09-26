<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmailLogs\Schemas;

use App\Models\EmailLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * One email as it went. The body is shown only where it was stored: a bulk
 * message keeps no copy per recipient, and the row says so rather than
 * showing nothing.
 */
class EmailLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The message'))->columns(3)->schema([
                TextEntry::make('to_address')->label(__('To'))->copyable(),
                TextEntry::make('from_address')->label(__('From'))->placeholder('—'),
                TextEntry::make('template_key')->label(__('Template'))->fontFamily('mono'),
                TextEntry::make('subject')->label(__('Subject'))->columnSpanFull(),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('mailer')->label(__('Mailer'))->placeholder('—'),
                TextEntry::make('provider_message_id')->label(__('Provider id'))->fontFamily('mono')->placeholder('—'),
                TextEntry::make('queued_at')->label(__('Queued'))->dateTime('j M Y, H:i:s')->placeholder('—'),
                TextEntry::make('sent_at')->label(__('Sent'))->dateTime('j M Y, H:i:s')->placeholder('—'),
                TextEntry::make('delivered_at')->label(__('Delivered'))->dateTime('j M Y, H:i:s')->placeholder(__('No report')),
                TextEntry::make('opened_at')->label(__('Opened'))->dateTime('j M Y, H:i')->placeholder('—'),
                TextEntry::make('click_count')->label(__('Clicks')),
                TextEntry::make('attempts')->label(__('Attempts')),
                TextEntry::make('blocked_reason')->label(__('Refused because'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('error')->label(__('Error'))->color('danger')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('As sent'))->schema([
                TextEntry::make('body')->hiddenLabel()->state(fn (EmailLog $r): HtmlString => $r->body_stored && $r->body_html
                    ? new HtmlString('<iframe title="'.e(__('Message')).'" srcdoc="'.e($r->body_html).'" style="width:100%;height:60vh;border:0;background:#fff;border-radius:8px;"></iframe>')
                    : new HtmlString('<p class="text-sm text-gray-500">'.e(__('The body was not stored for this message: bulk mail keeps one copy on the campaign, not one per recipient.')).'</p>')),
            ]),
        ]);
    }
}
