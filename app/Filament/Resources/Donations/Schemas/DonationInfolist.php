<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Schemas;

use App\Models\Donation;
use App\Models\PaymentWebhookEvent;
use App\Models\Refund;
use App\Payments\PayloadScrubber;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;

/**
 * One gift, in full.
 *
 * ── Three timelines on one page ─────────────────────────────────────────────
 *
 * What the gateway said (the transaction and its scrubbed payload), what the
 * gateway sent (every webhook that named this reference, verified or not, in
 * order), and what people did (the activity log). A donor asking "where is my
 * money" is answered from this page without opening three others.
 *
 * ── The payload is shown scrubbed, and it was stored scrubbed ───────────────
 *
 * `PayloadScrubber` removed card numbers, PINs and OTPs before anything was
 * written. What appears here is what exists.
 *
 * ── Personal details are gated ──────────────────────────────────────────────
 *
 * Email, phone and address appear only for `donations.view_pii`. The
 * reference, amount and appeal are enough to reconcile a bank statement.
 */
class DonationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $pii = fn (): bool => auth()->user()?->can('donations.view_pii') ?? false;

        return $schema->components([
            Section::make(__('The gift'))->columns(3)->schema([
                TextEntry::make('reference')->label(__('Reference'))->fontFamily('mono')->copyable(),
                TextEntry::make('status')->label(__('Status'))->badge()->formatStateUsing(fn ($state) => $state->label()),
                TextEntry::make('created_at')->label(__('Started'))->dateTime('j M Y, H:i'),
                TextEntry::make('amount')->label(__('Amount'))->state(fn (Donation $r): string => $r->amount->format())->weight('bold'),
                TextEntry::make('fee')->label(__('Gateway fee'))->state(fn (Donation $r): string => $r->fee->format().($r->fee_covered_by_donor ? ' '.__('(covered by donor)') : '')),
                TextEntry::make('net')->label(__('Net to the foundation'))->state(fn (Donation $r): string => $r->net->format()),
                TextEntry::make('cause.title')->label(__('Appeal'))->placeholder(__('General Fund')),
                TextEntry::make('channel')->label(__('Via'))->placeholder('—'),
                TextEntry::make('paid_at')->label(__('Paid'))->dateTime('j M Y, H:i')->placeholder(__('Not paid')),
                TextEntry::make('recurring')
                    ->label(__('Regular'))
                    ->state(fn (Donation $r): string => $r->subscription_id !== null
                        ? __('Yes — :interval', ['interval' => $r->subscription?->interval ?? $r->recurring_interval])
                        : ($r->wants_recurring ? __('Asked for, not set up') : __('No'))),
                TextEntry::make('source')->label(__('Source'))->placeholder('—')
                    ->helperText(fn (Donation $r): ?string => $r->utm ? collect($r->utm)->map(fn ($v, $k) => "$k=$v")->implode(' · ') : null),
                TextEntry::make('deductible_amount')->label(__('Eligible for tax relief'))->state(fn (Donation $r): string => $r->deductible_amount->format()),
            ]),

            Section::make(__('The donor'))->columns(3)->schema([
                TextEntry::make('donor_name')->label(__('Name'))->formatStateUsing(fn (?string $s, Donation $r): string => ($s ?? '—').($r->is_anonymous ? ' '.__('(anonymous on the site)') : '')),
                TextEntry::make('donor_email')->label(__('Email'))->visible($pii)->copyable()->placeholder('—'),
                TextEntry::make('donor_phone')->label(__('Phone'))->visible($pii)->copyable()->placeholder('—'),
                TextEntry::make('donor.name')->label(__('Donor record'))->placeholder(__('None'))
                    ->url(fn (Donation $r): ?string => $r->donor ? route('filament.admin.resources.donors.view', $r->donor) : null),
                TextEntry::make('consent')->label(__('Marketing consent'))
                    ->state(fn (Donation $r): string => implode(', ', array_filter([$r->consent_email ? __('email') : null, $r->consent_sms ? __('SMS') : null])) ?: __('none')),
                TextEntry::make('consent_text')->label(__('What they agreed to'))->visible($pii)->columnSpanFull()->placeholder('—'),
                TextEntry::make('public_message')->label(__('Message for the wall'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('tribute')
                    ->label(__('Tribute'))
                    ->visible(fn (Donation $r): bool => $r->tribute_type !== null)
                    ->state(fn (Donation $r): string => ($r->tribute_type === 'memory' ? __('In memory of') : __('In honour of')).' '.$r->tribute_name)
                    ->columnSpanFull(),
            ]),

            Section::make(__('Receipt'))->columns(3)->schema([
                TextEntry::make('receipt.receipt_number')->label(__('Number'))->fontFamily('mono')->placeholder(__('Not issued')),
                TextEntry::make('receipt.issued_on')->label(__('Issued'))->date('j M Y')->placeholder('—'),
                TextEntry::make('receipt.sent_at')->label(__('Sent'))->dateTime('j M Y, H:i')->placeholder(__('Not sent')),
            ]),

            Section::make(__('The payment, as the gateway had it'))->collapsible()->schema([
                Grid::make(3)->schema([
                    TextEntry::make('transaction.gateway')->label(__('Gateway'))->placeholder('—'),
                    TextEntry::make('transaction.gateway_reference')->label(__('Gateway reference'))->fontFamily('mono')->placeholder('—'),
                    TextEntry::make('transaction.status')->label(__('Transaction'))->badge()->formatStateUsing(fn ($state) => $state?->value ?? '—'),
                    TextEntry::make('transaction.amount_paid_minor')->label(__('Settled'))->state(fn (Donation $r): string => $r->transaction?->amount_paid_minor !== null ? number_format($r->transaction->amount_paid_minor / 100, 2).' '.$r->transaction->currency_paid : '—'),
                    TextEntry::make('transaction.verified_at')->label(__('Verified'))->dateTime('j M Y, H:i')->placeholder('—'),
                    TextEntry::make('transaction.reconciled_at')->label(__('Reconciled'))->dateTime('j M Y, H:i')->placeholder(__('Not yet')),
                    TextEntry::make('transaction.mismatch_reason')->label(__('Mismatch'))->color('danger')->placeholder('—')->columnSpanFull(),
                ]),
                TextEntry::make('payload')
                    ->label(__('Response payload (scrubbed)'))
                    ->helperText(__('What the gateway sent back, with the authorisation code and anything card-shaped removed. Contact details are shown only to somebody who may see them.'))
                    ->state(fn (Donation $r): HtmlString => new HtmlString('<pre class="whitespace-pre-wrap text-xs">'.e(json_encode(
                        app(PayloadScrubber::class)->forDisplay($r->transaction?->response_payload ?? [], $pii()),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    )).'</pre>')),
            ]),

            Section::make(__('Webhooks for this payment'))->collapsible()->schema([
                TextEntry::make('webhooks')
                    ->hiddenLabel()
                    ->state(fn (Donation $r): HtmlString => static::webhooks($r)),
            ]),

            Section::make(__('Refunds'))
                ->visible(fn (Donation $r): bool => $r->transaction?->refunds()->exists() ?? false)
                ->schema([
                    TextEntry::make('refunds')->hiddenLabel()->state(fn (Donation $r): HtmlString => static::refunds($r)),
                ]),

            Section::make(__('Audit trail'))->collapsible()->collapsed()->schema([
                TextEntry::make('audit')->hiddenLabel()->state(fn (Donation $r): HtmlString => static::audit($r)),
            ]),

            Section::make(__('Internal notes'))->schema([
                TextEntry::make('notes')->hiddenLabel()->placeholder(__('None'))->prose(),
            ]),
        ]);
    }

    private static function webhooks(Donation $donation): HtmlString
    {
        $reference = $donation->transaction?->gateway_reference;

        if ($reference === null) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('No gateway reference.')).'</p>');
        }

        $rows = PaymentWebhookEvent::query()
            ->where('gateway_reference', $reference)
            ->orderBy('received_at')
            ->get()
            ->map(fn (PaymentWebhookEvent $e): string => sprintf(
                '<li class="py-1"><span class="text-xs text-gray-500">%s</span> — <strong>%s</strong> · %s · %s%s</li>',
                e($e->received_at?->format('j M Y, H:i:s') ?? ''),
                e((string) $e->event_type),
                $e->signature_valid ? e(__('signature valid')) : '<span class="text-red-600">'.e(__('SIGNATURE INVALID')).'</span>',
                $e->isProcessed() ? e(__('processed')) : e(__('not processed')),
                $e->processing_error ? ' · <span class="text-red-600">'.e((string) $e->processing_error).'</span>' : '',
            ))
            ->implode('');

        return new HtmlString($rows === '' ? '<p class="text-sm text-gray-500">'.e(__('None received yet.')).'</p>' : '<ul class="text-sm">'.$rows.'</ul>');
    }

    private static function refunds(Donation $donation): HtmlString
    {
        $rows = $donation->transaction->refunds()->latest('id')->get()->map(fn (Refund $refund): string => sprintf(
            '<li class="py-1">%s — <strong>%s</strong> · %s%s</li>',
            e($refund->amount->format()),
            e($refund->status),
            e($refund->reason),
            $refund->failure_reason ? ' · <span class="text-red-600">'.e($refund->failure_reason).'</span>' : '',
        ))->implode('');

        return new HtmlString('<ul class="text-sm">'.$rows.'</ul>');
    }

    private static function audit(Donation $donation): HtmlString
    {
        $rows = Activity::query()
            ->where('subject_type', $donation->getMorphClass())
            ->where('subject_id', $donation->getKey())
            ->with('causer')
            ->orderBy('created_at')
            ->get()
            ->map(function (Activity $a): string {
                $changes = collect($a->properties['attributes'] ?? [])
                    ->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))
                    ->implode(', ');

                return sprintf(
                    '<li class="py-1"><span class="text-xs text-gray-500">%s</span> — %s%s%s</li>',
                    e($a->created_at->format('j M Y, H:i:s')),
                    e((string) $a->description),
                    $a->causer ? ' · '.e($a->causer->name ?? 'user') : ' · '.e(__('system')),
                    $changes !== '' ? '<br><span class="text-xs">'.e($changes).'</span>' : '',
                );
            })
            ->implode('');

        return new HtmlString($rows === '' ? '<p class="text-sm text-gray-500">'.e(__('Nothing recorded.')).'</p>' : '<ul class="text-sm">'.$rows.'</ul>');
    }
}
