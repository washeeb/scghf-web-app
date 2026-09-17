<?php

declare(strict_types=1);

namespace App\Payments;

use App\Communications\MessageDispatcher;
use App\Models\Donation;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Refund;
use App\ValueObjects\Money;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * When money behaves oddly, a person hears about it.
 *
 * Phase 8 held a mismatched payment for review and wrote a critical log
 * line. A log line on shared hosting is read by nobody. This sends the
 * same fact to the alerts address — at once for a mismatch, hourly in
 * aggregate for a run of failures or refunds — through the ordinary
 * template and outbox, so it is one more message in the log like any
 * other and never a thing that can itself fail loudly.
 */
final class AnomalyAlerts
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    /** A payment settled for a different amount or currency than expected. */
    public function mismatch(Donation|Order $payable, PaymentTransaction $transaction): void
    {
        $what = $payable instanceof Donation ? __('Donation') : __('Order');

        $this->send(
            'mismatch:'.$transaction->getKey(),
            __(':what :reference settled for a different amount', ['what' => $what, 'reference' => $payable->reference]),
            new HtmlString(sprintf(
                '<p>%s</p><p>%s</p>',
                e((string) $transaction->mismatch_reason),
                e(__('It has been held for review and will not be receipted until somebody looks at it.')),
            )),
            $payable,
        );
    }

    /**
     * The hourly sweep: too many failures, too many refunds.
     *
     * @return array<string, int> what was found, for the command's output
     */
    public function sweep(): array
    {
        $failedLine = (int) config('payments.paystack.anomalies.failed_per_hour', 10);
        $refundLine = (int) config('payments.paystack.anomalies.refunds_per_day', 3);

        $failed = PaymentTransaction::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subHour())
            ->count();

        $refunds = Refund::query()
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_PROCESSED])
            ->where('approved_at', '>=', now()->subDay())
            ->count();

        if ($failedLine > 0 && $failed > $failedLine) {
            $this->send(
                'failed:'.now()->format('YmdH'),
                __(':count failed payments in the last hour', ['count' => $failed]),
                new HtmlString('<p>'.e(__('More than :line failed payments in an hour is unusual. It can be a gateway outage, a run of declined cards, or somebody testing stolen card numbers against the donation form. Check Finance → Webhook events and the Paystack dashboard.', ['line' => $failedLine])).'</p>'),
            );
        }

        if ($refundLine > 0 && $refunds > $refundLine) {
            $total = Money::ofMinor((int) Refund::query()
                ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_PROCESSED])
                ->where('approved_at', '>=', now()->subDay())
                ->sum('amount_minor'));

            $this->send(
                'refunds:'.now()->format('Ymd'),
                __(':count refunds approved in the last day', ['count' => $refunds]),
                new HtmlString('<p>'.e(__(':total refunded in twenty-four hours, across :count refunds. Every refund needs two people, so this is not one person acting alone — but it is worth a look at who approved what.', ['total' => $total->format(), 'count' => $refunds])).'</p>'),
            );
        }

        return ['failed' => $failed, 'refunds' => $refunds];
    }

    private function send(string $key, string $headline, HtmlString $detail, ?object $related = null): void
    {
        $to = (string) (setting('communications.alert_email') ?: setting('contact.email_donations') ?: setting('contact.email_general', ''));

        if ($to === '' || str_contains($to, '{{')) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('admin.payment_anomaly', $to, [
                'headline' => $headline,
                'detail' => $detail,
                'admin_url' => route('filament.admin.resources.donations.index'),
            ], [
                'related' => $related,
                'priority' => 1,
                'idempotency_key' => 'admin.payment_anomaly:'.$key,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
