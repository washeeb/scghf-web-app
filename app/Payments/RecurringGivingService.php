<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\DonationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Donation;
use App\Models\DonationItem;
use App\Models\DonationPlan;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\ValueObjects\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sets up and runs recurring gifts.
 *
 * A subscription is established FROM A SETTLED DONATION, never from a form
 * alone. The first gift is what produces the authorization the later ones are
 * charged against, so a subscription created before any money has moved would
 * be a standing commitment with no way to honour it.
 *
 * @see Subscription for the mobile-money reusability caveat
 */
final class RecurringGivingService
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly ReceiptIssuer $receipts,
    ) {}

    /**
     * Turn a completed first gift into a standing commitment.
     */
    public function establish(
        Donation $donation,
        string $interval = Subscription::INTERVAL_MONTHLY,
        ?DonationPlan $plan = null,
    ): Subscription {
        if (! $donation->isCompleted()) {
            throw new RuntimeException(
                'A subscription is established from a gift that actually went through. '
                .'This donation is '.$donation->status->value.'.'
            );
        }

        if ($donation->donor === null) {
            throw new RuntimeException(
                'A recurring gift needs a donor record — there is nobody to charge again, and '
                .'nobody to tell when it fails.'
            );
        }

        $transaction = $donation->transaction;

        $subscription = Subscription::create([
            'donor_id' => $donation->donor_id,
            'donation_plan_id' => $plan?->getKey(),
            'cause_id' => $donation->cause_id,
            'division_id' => $donation->division_id,
            'amount' => $donation->amount,
            'currency' => $donation->currency,
            'interval' => $interval,
            'driver' => Subscription::DRIVER_MANAGED,
            'status' => SubscriptionStatus::Active,
            'started_on' => ($donation->paid_at ?? now())->toDateString(),
            'authorization_code' => $transaction?->authorization_code,
            /*
             * Taken from what the gateway actually said, not assumed. Card
             * authorizations are normally reusable; mobile-money ones often are
             * not, and assuming otherwise would fail a donor's gift every month.
             */
            'authorization_reusable' => $this->authorizationIsReusable($transaction),
            'channel' => $transaction?->channel,
            'card_last4' => $transaction?->card_last4,
        ]);

        // The first cycle is the gift already made, so the next one is a full
        // interval away rather than today.
        $subscription->forceFill([
            'next_charge_on' => $subscription->advanceFrom(
                Carbon::parse($subscription->started_on)
            )->toDateString(),
        ])->save();

        $donation->forceFill(['subscription_id' => $subscription->getKey()])->save();

        // "It is set up, and here is how to change it" — with the signed link
        // that lets a donor with no account manage the gift.
        app(RecurringNotifier::class)->established($subscription->refresh()->load(['donor', 'cause']));

        return $subscription->refresh();
    }

    /**
     * Charge everything due, one subscription at a time.
     *
     * Each is its own transaction and its own try/catch, so one donor's expired
     * card cannot stop the rest of the run. On shared hosting this is driven by
     * cron, which means it may execute minutes late and may overlap with the
     * previous run — hence the per-cycle unique index on `subscription_charges`,
     * which makes a double run a no-op rather than a double charge.
     *
     * @return array{due: int, charged: int, failed: int, blocked: int, detail: array<int, string>}
     */
    public function chargeDue(?Carbon $on = null, bool $execute = true): array
    {
        $on ??= now();

        $summary = ['due' => 0, 'charged' => 0, 'failed' => 0, 'blocked' => 0, 'detail' => []];

        /*
         * Blocked subscriptions are REPORTED, not silently skipped. A donor who
         * believes they are giving monthly and is not deserves to be told, and
         * a run that quietly does nothing looks identical to a run with nothing
         * to do.
         */
        foreach (Subscription::query()->blocked($on)->get() as $blocked) {
            $summary['blocked']++;
            $summary['detail'][] = sprintf(
                'BLOCKED %s (%s): %s',
                $blocked->reference,
                $blocked->donor?->displayName() ?? 'unknown donor',
                $blocked->blockedReason(),
            );

            if ($execute) {
                $this->chargeRow($blocked, $on)->markSkipped((string) $blocked->blockedReason());
            }
        }

        foreach (Subscription::query()->due($on)->get() as $subscription) {
            $summary['due']++;

            if (! $execute) {
                continue;
            }

            try {
                $this->chargeOne($subscription, $on);
                $summary['charged']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                $summary['detail'][] = sprintf('FAILED %s: %s', $subscription->reference, $e->getMessage());

                Log::error('Recurring charge failed.', [
                    'subscription' => $subscription->reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * One cycle: a donation, a charge against the stored authorization, and the
     * bookkeeping either way.
     */
    public function chargeOne(Subscription $subscription, ?Carbon $on = null): SubscriptionCharge
    {
        $on ??= now();
        $charge = $this->chargeRow($subscription, $on);

        if ($charge->status === SubscriptionCharge::STATUS_SUCCEEDED) {
            // Already paid this cycle. The unique index makes a second run of
            // the cron a no-op rather than a second charge.
            return $charge;
        }

        $donation = DB::transaction(function () use ($subscription): Donation {
            $donation = Donation::create([
                'donor_id' => $subscription->donor_id,
                'subscription_id' => $subscription->getKey(),
                'cause_id' => $subscription->cause_id,
                'division_id' => $subscription->division_id,
                'amount' => $subscription->amount,
                'currency' => $subscription->currency,
                'status' => DonationStatus::Pending,
                'channel' => $subscription->channel,
                'donor_name' => $subscription->donor?->displayName(),
                'donor_email' => $subscription->donor?->email,
                'donor_phone' => $subscription->donor?->phone,
                'consent_email' => (bool) $subscription->donor?->consent_email,
                'consent_sms' => (bool) $subscription->donor?->consent_sms,
            ]);

            DonationItem::create([
                'donation_id' => $donation->getKey(),
                'cause_id' => $subscription->cause_id,
                'division_id' => $subscription->division_id,
                'amount' => $subscription->amount,
                'currency' => $subscription->currency,
                // Snapshotted fresh for THIS cycle, not copied from the first
                // gift: an approval that lapsed since must not carry forward.
                ...DonationItem::snapshotDeductibility($subscription->cause),
            ]);

            $donation->load('items')->assertItemsReconcile();
            $donation->recalculateDeductible();

            return $donation->refresh();
        });

        $transaction = $this->payments->chargeStored(
            $donation,
            (string) $subscription->authorization_code,
            [
                'reference' => $donation->reference,
                'metadata' => ['subscription' => $subscription->reference],
            ],
        );

        if (! $transaction->status->isSettled()) {
            $reason = $transaction->mismatch_reason ?? 'The stored authorization was declined.';

            $charge->markFailed($reason);
            $subscription->recordFailure($reason, (int) config('payments.recurring.max_failures', 3));

            // Dunning, in the tone of a thank-you: "this one did not go
            // through, we will try again on <date>", and after the last
            // attempt, "we have stopped; start again when you are ready".
            app(RecurringNotifier::class)->failed($subscription->refresh()->load(['donor', 'cause']), $reason);

            return $charge;
        }

        $donation->refresh();

        $charge->markSucceeded($donation);
        $subscription->recordSuccess($donation);

        // The receipt for a recurring gift is issued the same way as any other.
        // A donor giving monthly is entitled to twelve acknowledgements, not one.
        $this->receipts->issue($donation);

        return $charge->fresh();
    }

    /**
     * The row for this cycle, created if it does not exist.
     *
     * `firstOrCreate` on `(subscription_id, scheduled_on)`, which is the unique
     * index — so two overlapping cron runs land on the same row rather than
     * charging twice.
     */
    private function chargeRow(Subscription $subscription, Carbon $on): SubscriptionCharge
    {
        return SubscriptionCharge::firstOrCreate(
            [
                'subscription_id' => $subscription->getKey(),
                'scheduled_on' => ($subscription->next_charge_on ?? $on)->toDateString(),
            ],
            [
                'amount' => $subscription->amount,
                'currency' => $subscription->currency,
                'status' => SubscriptionCharge::STATUS_SCHEDULED,
            ],
        );
    }

    /**
     * Whether the gateway's authorization can be charged again.
     *
     * Reads Paystack's own `reusable` flag where it is present. Absent, it
     * falls back to the channel: cards are reusable, mobile money is treated as
     * NOT reusable until proven otherwise on the live merchant account.
     *
     * The default is the conservative one on purpose. Assuming reusability and
     * being wrong produces a failed charge on a donor's account every month;
     * assuming the opposite produces a report saying "these donors need to give
     * again", which somebody can act on.
     */
    private function authorizationIsReusable(?PaymentTransaction $transaction): bool
    {
        if ($transaction === null || blank($transaction->authorization_code)) {
            return false;
        }

        $payload = $transaction->response_payload ?? [];
        $reusable = $payload['data']['authorization']['reusable'] ?? $payload['authorization']['reusable'] ?? null;

        if ($reusable !== null) {
            return (bool) $reusable;
        }

        return $transaction->channel === 'card';
    }

    /** What a subscription has given in total, for a donor's own page. */
    public function lifetimeValue(Subscription $subscription): Money
    {
        return $subscription->totalCharged();
    }
}
