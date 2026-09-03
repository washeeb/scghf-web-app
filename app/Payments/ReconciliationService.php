<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\DonationStatus;
use App\Enums\PaymentStatus;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The daily check that the ledger still agrees with the gateway.
 *
 * Backups that are never restored and ledgers that are never reconciled fail
 * the same way: silently, and only discovered when it matters. This runs from
 * cron and answers five questions:
 *
 *   1. is anything stuck pending that the gateway has already settled?
 *   2. is anything stuck pending that the donor clearly abandoned?
 *   3. is anything flagged as a mismatch, still waiting for a human?
 *   4. did any webhook arrive and never get processed?
 *   5. is any completed gift missing its acknowledgement?
 *
 * Questions 3, 4 and 5 are REPORTED, never fixed automatically. A mismatch
 * needs somebody to compare the settlement against what the site expected; an
 * unprocessed webhook usually means a bug worth looking at rather than a retry;
 * and silently issuing a missing receipt could put a second document in front
 * of a donor who already has one.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly PaymentManager $payments,
    ) {}

    /**
     * @return array{
     *     window: string, checked: int, recovered: int, abandoned: int,
     *     mismatches: int, unprocessed_webhooks: int, missing_receipts: int,
     *     detail: array<int, string>
     * }
     */
    public function run(bool $execute = true, ?Carbon $since = null): array
    {
        $lookback = (int) config('payments.reconciliation.lookback_days', 7);
        $since ??= now()->subDays($lookback);

        $summary = [
            'window' => $since->toDateString().' → '.now()->toDateString(),
            'checked' => 0, 'recovered' => 0, 'abandoned' => 0,
            'mismatches' => 0, 'unprocessed_webhooks' => 0, 'missing_receipts' => 0,
            'detail' => [],
        ];

        $this->recoverSettledButPending($summary, $execute, $since);
        $this->abandonStale($summary, $execute);
        $this->reportMismatches($summary);
        $this->reportUnprocessedWebhooks($summary);
        $this->reportMissingReceipts($summary);

        return $summary;
    }

    /**
     * Payments the gateway settled that this site never heard about.
     *
     * The failure this exists for: a webhook that was never delivered, or was
     * delivered while the site was down. The donor paid, Paystack knows, and
     * the foundation's books do not — which is the one direction of error that
     * loses a real gift rather than merely confusing a report.
     *
     * @param  array<string, mixed>  $summary
     */
    private function recoverSettledButPending(array &$summary, bool $execute, Carbon $since): void
    {
        $pending = PaymentTransaction::query()
            ->whereIn('status', [PaymentStatus::Initialised->value, PaymentStatus::Pending->value])
            ->where('created_at', '>=', $since)
            // Offline gifts have no gateway to ask.
            ->where('gateway', '!=', PaymentTransaction::GATEWAY_OFFLINE)
            ->get();

        foreach ($pending as $transaction) {
            $summary['checked']++;

            if (! $execute) {
                continue;
            }

            try {
                $status = $this->payments->verifyAndSettle($transaction);

                if ($status === PaymentStatus::Success) {
                    $summary['recovered']++;
                    $summary['detail'][] = sprintf(
                        'RECOVERED %s — the gateway had settled it and we had not recorded it.',
                        $transaction->gateway_reference,
                    );
                }

                if ($status === PaymentStatus::Mismatch) {
                    $summary['detail'][] = sprintf(
                        'MISMATCH %s — held for review, not completed.',
                        $transaction->gateway_reference,
                    );
                }
            } catch (Throwable $e) {
                // One unreachable reference must not stop the run.
                $summary['detail'][] = sprintf(
                    'ERROR %s: %s',
                    $transaction->gateway_reference,
                    $e->getMessage(),
                );
            }
        }
    }

    /**
     * Payment pages the donor opened and never came back to.
     *
     * Marked `abandoned` rather than `failed`: the gateway never declined
     * anything, and the difference decides whether it is worth following up.
     *
     * @param  array<string, mixed>  $summary
     */
    private function abandonStale(array &$summary, bool $execute): void
    {
        $stale = PaymentTransaction::query()
            ->stale()
            ->where('gateway', '!=', PaymentTransaction::GATEWAY_OFFLINE)
            ->get();

        foreach ($stale as $transaction) {
            $summary['abandoned']++;

            if (! $execute) {
                continue;
            }

            /*
             * Verified one last time before being written off. A mobile-money
             * prompt approved forty minutes late is a real gift, and abandoning
             * it without asking the gateway would throw it away.
             */
            if ($this->payments->verifyAndSettle($transaction) === PaymentStatus::Success) {
                $summary['abandoned']--;
                $summary['recovered']++;

                continue;
            }

            $transaction->refresh()->markAbandoned();

            $donation = $transaction->payable;

            if ($donation instanceof Donation && $donation->status === DonationStatus::Pending) {
                $donation->forceFill(['status' => DonationStatus::Abandoned])->save();
            }
        }
    }

    /** @param array<string, mixed> $summary */
    private function reportMismatches(array &$summary): void
    {
        $mismatches = PaymentTransaction::query()->needingReview()->get();

        $summary['mismatches'] = $mismatches->count();

        foreach ($mismatches as $transaction) {
            $summary['detail'][] = sprintf(
                'NEEDS REVIEW %s: %s',
                $transaction->gateway_reference,
                $transaction->mismatch_reason,
            );
        }

        if ($summary['mismatches'] > 0) {
            Log::warning('Payment mismatches are still awaiting review.', [
                'count' => $summary['mismatches'],
            ]);
        }
    }

    /**
     * Authenticated webhooks that arrived and were never acted on.
     *
     * Almost always a bug rather than a transient failure — the queue retries
     * on its own — so this reports rather than re-queues.
     *
     * @param  array<string, mixed>  $summary
     */
    private function reportUnprocessedWebhooks(array &$summary): void
    {
        $stuck = PaymentWebhookEvent::query()
            ->pending()
            ->where('received_at', '<', now()->subHour())
            ->get();

        $summary['unprocessed_webhooks'] = $stuck->count();

        foreach ($stuck as $event) {
            $summary['detail'][] = sprintf(
                'UNPROCESSED webhook #%d (%s) received %s: %s',
                $event->id,
                $event->event_type,
                $event->received_at->diffForHumans(),
                $event->processing_error ?? 'never attempted',
            );
        }
    }

    /**
     * Completed gifts with no acknowledgement.
     *
     * Reported, not issued. Silently generating one could put a second document
     * in front of a donor who already has theirs — and a duplicate
     * acknowledgement for a section 100 claim is worse than a late one.
     *
     * @param  array<string, mixed>  $summary
     */
    private function reportMissingReceipts(array &$summary): void
    {
        $missing = Donation::query()
            ->completed()
            ->whereDoesntHave('receipt')
            ->where('paid_at', '>=', now()->subDays((int) config('payments.reconciliation.lookback_days', 7)))
            ->get();

        $summary['missing_receipts'] = $missing->count();

        foreach ($missing as $donation) {
            $summary['detail'][] = sprintf(
                'NO ACKNOWLEDGEMENT for %s (%s, %s)',
                $donation->reference,
                $donation->amount->format(),
                $donation->paid_at?->toDateString() ?? 'unknown date',
            );
        }
    }

    /** Whether anything in a run needs a person to look at it. */
    public function needsAttention(array $summary): bool
    {
        return $summary['mismatches'] > 0
            || $summary['unprocessed_webhooks'] > 0
            || $summary['missing_receipts'] > 0;
    }

    /** Receipts issued in a financial year, for the audit pack. */
    public function receiptSeriesFor(int $year): array
    {
        $receipts = DonationReceipt::query()->inFinancialYear($year)->get();

        $sequences = $receipts->pluck('sequence')->sort()->values();
        $expected = range(1, $sequences->count());

        return [
            'count' => $receipts->count(),
            'first' => $receipts->first()?->receipt_number,
            'last' => $receipts->last()?->receipt_number,
            // The series must be unbroken. A gap has to be explainable, and
            // this is what makes it findable before an auditor finds it.
            'gaps' => array_values(array_diff($expected, $sequences->all())),
        ];
    }
}
