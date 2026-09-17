<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\Schemas;

use App\Models\Donation;
use App\Models\Donor;
use App\Models\EmailLog;
use App\Models\ScheduledMessage;
use App\Models\Subscription;
use App\ValueObjects\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * One donor: what they have given, what stands, what was sent to them.
 *
 * Lifetime value, first and last gift, and average — computed from the
 * donations table on view rather than trusted from the counters, so a
 * discrepancy between the two is visible here rather than reported elsewhere.
 */
class DonorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Giving'))->columns(4)->schema([
                TextEntry::make('lifetime')->label(__('Lifetime'))->state(fn (Donor $r): string => Money::ofMinor((int) $r->total_donated_minor)->format())->weight('bold'),
                TextEntry::make('donation_count')->label(__('Gifts')),
                TextEntry::make('average')->label(__('Average gift'))
                    ->state(fn (Donor $r): string => $r->donation_count > 0 ? Money::ofMinor(intdiv((int) $r->total_donated_minor, (int) $r->donation_count))->format() : '—'),
                TextEntry::make('frequency')->label(__('Frequency'))
                    ->state(fn (Donor $r): string => self::frequency($r)),
                TextEntry::make('first_donated_at')->label(__('First gift'))->date('j M Y')->placeholder('—'),
                TextEntry::make('last_donated_at')->label(__('Last gift'))->date('j M Y')->placeholder('—'),
                TextEntry::make('regular')->label(__('Regular gifts'))
                    ->state(fn (Donor $r): string => $r->subscriptions()->where('status', 'active')->count().' '.__('active')),
                TextEntry::make('consent')->label(__('Consent'))
                    ->state(fn (Donor $r): string => implode(', ', array_filter([$r->consent_email ? __('email') : null, $r->consent_sms ? __('SMS') : null])) ?: __('none')),
                TextEntry::make('tags.name')->label(__('Tags'))->badge()->color('gray')->placeholder(__('None'))->columnSpanFull(),
            ]),

            Section::make(__('Contact'))->columns(3)->schema([
                TextEntry::make('email')->label(__('Email'))->copyable()->placeholder('—'),
                TextEntry::make('phone')->label(__('Phone'))->copyable()->placeholder('—'),
                TextEntry::make('address')->label(__('Address'))->state(fn (Donor $r): string => collect([$r->address, $r->city])->filter()->implode(', ') ?: '—'),
                TextEntry::make('user.email')->label(__('Site account'))->placeholder(__('None')),
                TextEntry::make('organisation_name')->label(__('Organisation'))->placeholder('—'),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
            ]),

            Section::make(__('Gifts'))->schema([
                TextEntry::make('gifts')->hiddenLabel()->state(fn (Donor $r): HtmlString => self::gifts($r)),
            ]),

            Section::make(__('Regular gifts'))
                ->visible(fn (Donor $r): bool => $r->subscriptions()->exists())
                ->schema([
                    TextEntry::make('subs')->hiddenLabel()->state(fn (Donor $r): HtmlString => self::subscriptions($r)),
                ]),

            Section::make(__('Sent to them'))->collapsible()->collapsed()->schema([
                TextEntry::make('messages')->hiddenLabel()->state(fn (Donor $r): HtmlString => self::messages($r)),
            ]),
        ]);
    }

    private static function frequency(Donor $donor): string
    {
        if ($donor->donation_count < 2 || $donor->first_donated_at === null || $donor->last_donated_at === null) {
            return $donor->donation_count === 1 ? __('Once') : '—';
        }

        $months = max(1, $donor->first_donated_at->diffInMonths($donor->last_donated_at));
        $perMonth = $donor->donation_count / $months;

        return match (true) {
            $perMonth >= 0.9 => __('About monthly'),
            $perMonth >= 0.3 => __('Every few months'),
            default => __('Occasional'),
        };
    }

    private static function gifts(Donor $donor): HtmlString
    {
        $rows = $donor->donations()->with('cause')->latest('created_at')->limit(50)->get()
            ->map(fn (Donation $d): string => sprintf(
                '<li class="py-1"><a class="underline" href="%s">%s</a> · %s · %s · %s · <em>%s</em></li>',
                e(route('filament.admin.resources.donations.view', $d)),
                e($d->reference),
                e(($d->paid_at ?? $d->created_at)->format('j M Y')),
                e($d->amount->format()),
                e($d->cause?->title ?? 'General Fund'),
                e($d->status->label()),
            ))->implode('');

        return new HtmlString($rows === '' ? '<p class="text-sm text-gray-500">'.e(__('No gifts yet.')).'</p>' : '<ul class="text-sm">'.$rows.'</ul>');
    }

    private static function subscriptions(Donor $donor): HtmlString
    {
        $rows = $donor->subscriptions()->with('cause')->get()->map(fn (Subscription $s): string => sprintf(
            '<li class="py-1"><a class="underline" href="%s">%s</a> · %s %s · %s · <em>%s</em></li>',
            e(route('filament.admin.resources.subscriptions.view', $s)),
            e($s->reference),
            e($s->amount->format()),
            e($s->interval),
            e($s->cause?->title ?? 'General Fund'),
            e($s->status->label()),
        ))->implode('');

        return new HtmlString('<ul class="text-sm">'.$rows.'</ul>');
    }

    private static function messages(Donor $donor): HtmlString
    {
        if (blank($donor->email)) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('No email address.')).'</p>');
        }

        $sent = EmailLog::query()->where('to_address', $donor->email)->latest('created_at')->limit(30)->get()
            ->map(fn (EmailLog $m): string => sprintf('<li class="py-1"><span class="text-xs text-gray-500">%s</span> — %s · <em>%s</em></li>', e($m->created_at->format('j M Y, H:i')), e((string) $m->subject), e((string) $m->status)));

        $queued = ScheduledMessage::query()->where('to_address', $donor->email)->whereNull('sent_at')->latest('created_at')->limit(10)->get()
            ->map(fn (ScheduledMessage $m): string => sprintf('<li class="py-1"><span class="text-xs text-gray-500">%s</span> — %s · <em>%s</em></li>', e($m->created_at->format('j M Y, H:i')), e((string) $m->template_key), e(__('queued'))));

        $rows = $queued->concat($sent)->implode('');

        return new HtmlString($rows === '' ? '<p class="text-sm text-gray-500">'.e(__('Nothing sent yet.')).'</p>' : '<ul class="text-sm">'.$rows.'</ul>');
    }
}
