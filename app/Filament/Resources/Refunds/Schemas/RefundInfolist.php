<?php

declare(strict_types=1);

namespace App\Filament\Resources\Refunds\Schemas;

use App\Models\Refund;
use App\Payments\PayloadScrubber;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class RefundInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The refund'))->columns(3)->schema([
                TextEntry::make('amount')->label(__('Amount'))->state(fn (Refund $r): string => $r->amount->format())->weight('bold'),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('transaction.gateway_reference')->label(__('Payment'))->fontFamily('mono'),
                TextEntry::make('reason')->label(__('Why'))->columnSpanFull(),
                TextEntry::make('requestedBy.name')->label(__('Requested by'))->placeholder('—'),
                TextEntry::make('approvedBy.name')->label(__('Approved by'))->placeholder(__('Not yet')),
                TextEntry::make('approved_at')->label(__('Approved'))->dateTime('j M Y, H:i')->placeholder('—'),
                TextEntry::make('gateway_reference')->label(__('Gateway refund id'))->fontFamily('mono')->placeholder('—'),
                TextEntry::make('processed_at')->label(__('Processed'))->dateTime('j M Y, H:i')->placeholder('—'),
                TextEntry::make('failure_reason')->label(__('Failure'))->color('danger')->placeholder('—'),
            ]),
            Section::make(__('Gateway response'))->collapsible()->collapsed()->schema([
                TextEntry::make('payload')->hiddenLabel()->state(fn (Refund $r): HtmlString => new HtmlString('<pre class="whitespace-pre-wrap text-xs">'.e(json_encode(app(PayloadScrubber::class)->forDisplay($r->response_payload ?? [], auth()->user()?->can('donations.view_pii') ?? false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>')),
            ]),
        ]);
    }
}
