<?php

declare(strict_types=1);

namespace App\Payments;

use App\Communications\MessageDispatcher;
use App\Models\Subscription;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Telling a regular donor what is happening to their standing gift.
 *
 * ── Three messages, and the tone of each is the point ──────────────────────
 *
 * Established: "it is set up, here is how to change it". Failed: "this
 * month's gift did not go through, we will try again on <date>, nothing to do
 * unless the card has changed". Paused after repeated failure: "we have
 * stopped trying; here is the link to start again when you are ready". None
 * of them is a demand. A donor whose card expired is a donor, not a debtor,
 * and the dunning that works is the one that assumes goodwill.
 *
 * ── Every message carries a signed management link ─────────────────────────
 *
 * Valid for sixty days, no sign-in needed. A donor who set up a monthly gift
 * from a phone at a church event does not have an account and should not
 * need one to stop the gift; the brief's "from a signed link in an email" is
 * this link. Cancel is the one action that needs a confirmation click on the
 * page, so a forwarded link cannot cancel somebody's gift by being opened.
 *
 * ── SMS as well as email for a failure ──────────────────────────────────────
 *
 * The donor whose mobile-money charge failed is more likely to read a text
 * than an email, and a failure is transactional: it reports on a charge they
 * agreed to.
 */
final class RecurringNotifier
{
    public function __construct(private readonly MessageDispatcher $dispatcher) {}

    public function established(Subscription $subscription): void
    {
        $donor = $subscription->donor;

        if ($donor === null || blank($donor->email)) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('recurring.established', (string) $donor->email, [
                'donor_name' => $donor->displayName(),
                'amount' => $subscription->amount,
                'interval' => $this->intervalWord($subscription),
                'cause_name' => $subscription->cause?->title,
                'next_date' => $subscription->next_charge_on,
                'manage_url' => $this->manageUrl($subscription),
            ], [
                'to_name' => $donor->displayName(),
                'related' => $subscription,
                'idempotency_key' => 'recurring.established:'.$subscription->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function failed(Subscription $subscription, string $reason): void
    {
        $donor = $subscription->donor;

        if ($donor === null) {
            return;
        }

        $paused = ! $subscription->status->isChargeable();
        $key = $paused ? 'recurring.paused' : 'recurring.failed';
        $idempotency = $key.':'.$subscription->reference.':'.$subscription->failed_attempts;

        if (filled($donor->email)) {
            try {
                $this->dispatcher->queueEmail($key, (string) $donor->email, [
                    'donor_name' => $donor->displayName(),
                    'amount' => $subscription->amount,
                    'interval' => $this->intervalWord($subscription),
                    'cause_name' => $subscription->cause?->title,
                    'reason' => $reason,
                    'next_date' => $subscription->next_charge_on,
                    'attempts' => (string) $subscription->failed_attempts,
                    'manage_url' => $this->manageUrl($subscription),
                ], [
                    'to_name' => $donor->displayName(),
                    'related' => $subscription,
                    'idempotency_key' => $idempotency,
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (filled($donor->phone)) {
            try {
                $this->dispatcher->queueSms('recurring.failed', (string) $donor->phone, [
                    'amount' => $subscription->amount->toMajorString(),
                    'next_date' => $subscription->next_charge_on?->format('j M') ?? '',
                ], [
                    'related' => $subscription,
                    'idempotency_key' => $idempotency.':sms',
                ]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** A signed link a donor can manage the gift from without signing in. */
    public function manageUrl(Subscription $subscription): string
    {
        return URL::temporarySignedRoute('giving.manage', now()->addDays(60), ['subscription' => $subscription->ulid]);
    }

    private function intervalWord(Subscription $subscription): string
    {
        return match ($subscription->interval) {
            Subscription::INTERVAL_WEEKLY => __('every week'),
            Subscription::INTERVAL_QUARTERLY => __('every three months'),
            Subscription::INTERVAL_ANNUALLY => __('every year'),
            default => __('every month'),
        };
    }
}
