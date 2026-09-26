<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Communications\MessageDispatcher;
use App\Enums\DonationStatus;
use App\Enums\OrderStatus;
use App\Models\ContactMessage;
use App\Models\Donation;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\SmsLog;
use App\Models\Subscriber;
use App\Models\VolunteerApplication;
use App\Payments\GivingReports;
use App\Shop\ShopReports;
use Illuminate\Console\Command;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The week, in one email, Monday morning.
 *
 * For the director who does not open the admin every day: what came in,
 * what went out, who joined, and what is waiting for a person. Drawn from
 * the same report classes the admin pages use, so the numbers are the
 * numbers. Off with one setting; sent once per week by idempotency key.
 */
class WeeklySummary extends Command
{
    protected $signature = 'scghf:weekly-summary {--execute : Send it rather than print it}';

    protected $description = 'Email last week in numbers to the alerts address';

    public function handle(MessageDispatcher $dispatcher): int
    {
        if (! setting('communications.weekly_summary', true)) {
            $this->info('The weekly summary is switched off.');

            return self::SUCCESS;
        }

        $from = now()->subWeek()->startOfWeek();
        $until = now()->subWeek()->endOfWeek();
        $week = $from->format('j M').' – '.$until->format('j M Y');

        $giving = GivingReports::between($from, $until);
        $shop = ShopReports::between($from, $until);
        $g = $giving->summary();
        $s = $shop->summary();
        $r = $giving->recurring();

        $lines = [
            __('Gifts: :count, :raised raised (:net after fees), :donors donors, :new new', [
                'count' => $g['gifts'], 'raised' => $g['raised']->format(), 'net' => $g['net']->format(), 'donors' => $g['donors'], 'new' => $giving->acquisition()['new'],
            ]),
            __('Regular giving: :active active, :started started, :stopped stopped, :failing failing', [
                'active' => $r['active'], 'started' => $r['started_in_period'], 'stopped' => $r['cancelled_in_period'], 'failing' => $r['failing'],
            ]),
            __('Shop: :orders orders, :goods in goods, :net net proceeds', ['orders' => $s['orders'], 'goods' => $s['goods']->format(), 'net' => $s['net']->format()]),
            __('Subscribers: :new joined, :left left', [
                'new' => Subscriber::query()->whereBetween('confirmed_at', [$from, $until])->count(),
                'left' => Subscriber::query()->whereBetween('unsubscribed_at', [$from, $until])->count(),
            ]),
            __('Messages: :emails emails and :sms texts sent, :bounced bounced', [
                'emails' => EmailLog::query()->whereBetween('sent_at', [$from, $until])->count(),
                'sms' => SmsLog::query()->whereBetween('sent_at', [$from, $until])->count(),
                'bounced' => EmailLog::query()->whereBetween('failed_at', [$from, $until])->count(),
            ]),
        ];

        $attention = array_filter([
            ($n = Donation::query()->where('status', DonationStatus::NeedsReview->value)->count()) ? __(':n donations needing review', ['n' => $n]) : null,
            ($n = PaymentTransaction::query()->where('status', 'success')->where('gateway', '!=', 'offline')->whereNull('reconciled_at')->count()) ? __(':n payments not yet reconciled', ['n' => $n]) : null,
            ($n = ContactMessage::query()->where('status', 'new')->count()) ? __(':n unanswered enquiries', ['n' => $n]) : null,
            ($n = Order::query()->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Packed->value])->count()) ? __(':n orders to pack or dispatch', ['n' => $n]) : null,
            ($n = VolunteerApplication::query()->whereIn('status', ['submitted', 'under_review'])->count()) ? __(':n volunteer applications waiting', ['n' => $n]) : null,
            ($n = ProductVariant::query()->where('tracks_stock', true)->where('is_active', true)->whereRaw('stock_on_hand - stock_held <= ?', [(int) setting('shop.low_stock_threshold', 5)])->count()) ? __(':n products low on stock', ['n' => $n]) : null,
        ]);

        foreach ($lines as $line) {
            $this->line($line);
        }

        foreach ($attention as $line) {
            $this->warn($line);
        }

        if (! $this->option('execute')) {
            return self::SUCCESS;
        }

        $to = (string) (setting('communications.alert_email') ?: setting('contact.email_general', ''));

        if ($to === '' || str_contains($to, '{{')) {
            $this->error('No alerts address is set (Settings → Email & SMS).');

            return self::FAILURE;
        }

        try {
            $dispatcher->queueEmail('admin.weekly_summary', $to, [
                'week' => $week,
                'summary' => new HtmlString('<ul>'.collect($lines)->map(fn (string $l): string => '<li>'.e($l).'</li>')->implode('').'</ul>'),
                'attention' => new HtmlString($attention === [] ? '<p>'.e(__('Nothing. A quiet week.')).'</p>' : '<ul>'.collect($attention)->map(fn (string $l): string => '<li>'.e($l).'</li>')->implode('').'</ul>'),
                'admin_url' => url('/'.config('admin.path')),
            ], ['idempotency_key' => 'admin.weekly_summary:'.$from->toDateString()]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Queued to '.$to.'.');

        return self::SUCCESS;
    }
}
