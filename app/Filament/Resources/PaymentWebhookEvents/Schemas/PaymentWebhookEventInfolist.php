<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentWebhookEvents\Schemas;

use App\Models\PaymentWebhookEvent;
use App\Payments\PayloadScrubber;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * One delivery. The raw body was stored as it arrived; it is shown through
 * the scrubber, so a PIN or a card number that a gateway should never send
 * but might is not on a staff screen.
 */
class PaymentWebhookEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('The delivery'))->columns(3)->schema([
                TextEntry::make('event_id')->label(__('Event id'))->fontFamily('mono'),
                TextEntry::make('event_type')->label(__('Event'))->badge(),
                TextEntry::make('received_at')->label(__('Received'))->dateTime('j M Y, H:i:s'),
                TextEntry::make('signature_valid')->label(__('Signature'))->state(fn (PaymentWebhookEvent $e): string => $e->signature_valid ? __('Valid') : __('INVALID'))->color(fn (PaymentWebhookEvent $e): string => $e->signature_valid ? 'success' : 'danger'),
                TextEntry::make('source_ip')->label(__('From'))->fontFamily('mono')->placeholder('—'),
                TextEntry::make('processed_at')->label(__('Processed'))->dateTime('j M Y, H:i:s')->placeholder(__('Not yet')),
                TextEntry::make('processing_error')->label(__('Error'))->color('danger')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('Payload (scrubbed)'))->schema([
                TextEntry::make('body')->hiddenLabel()->state(fn (PaymentWebhookEvent $e): HtmlString => new HtmlString(
                    '<pre class="whitespace-pre-wrap text-xs">'.e(json_encode(app(PayloadScrubber::class)->forDisplay($e->payload(), auth()->user()?->can('donations.view_pii') ?? false), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)).'</pre>'
                )),
            ]),
        ]);
    }
}
