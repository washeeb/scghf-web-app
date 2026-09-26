<?php

declare(strict_types=1);

namespace App\Payments;

use App\Communications\PhoneNumber;
use App\Enums\DonationStatus;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\DonationItem;
use App\Models\Donor;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assembles a gift and starts the charge.
 *
 * One place where a donation is built, so the donation form, an offline entry
 * and a recurring cycle all produce the same shape of record. Three
 * implementations of "make a donation" would eventually disagree, and the one
 * that disagreed would be the one that mis-stated a receipt.
 *
 * The order matters and is deliberate:
 *
 *   1. resolve the destination — the General Fund if none was chosen
 *   2. gross up if the donor is covering the fee
 *   3. write the donation and its items in ONE transaction
 *   4. reconcile the items against the total before anything leaves
 *   5. only then call the gateway
 *
 * Step 4 before step 5 is the important one. A gift whose parts do not sum to
 * the whole is a bug worth refusing to charge for, not one to discover on a
 * receipt.
 */
final class DonationService
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly FeeCalculator $fees,
    ) {}

    /**
     * Build a donation, ready to charge.
     *
     * @param  array<string, mixed>  $input  {
     *
     * @var Money $amount            what the donor chose to give
     * @var Cause|null $cause             destination; the General Fund if absent
     * @var array<int, array{cause: Cause, amount: Money, project_id?: int}>|null $designations   split giving
     * @var bool $cover_fee         gross up so the foundation nets the amount
     * @var string $donor_name        as it should appear on the acknowledgement
     * @var string|null $donor_email
     * @var string|null $donor_phone
     * @var bool $is_anonymous
     * @var array<string, mixed>|null $tribute          in memory / in honour of
     * @var bool $consent_email
     * @var bool $consent_sms
     * @var string|null $consent_text
     * @var string|null $consent_ip
     *                  }
     */
    public function create(array $input): Donation
    {
        $requested = $input['amount'] ?? throw new RuntimeException('A donation needs an amount.');

        if (! $requested instanceof Money) {
            throw new RuntimeException(
                'The amount must be a Money. Build it with Money::ofMajor() so a decimal cannot '
                .'be written as if it were pesewas.'
            );
        }

        $this->assertWithinLimits($requested);

        $coverFee = (bool) ($input['cover_fee'] ?? false)
            && (bool) config('payments.donations.allow_fee_cover', true);

        /*
         * Grossing up, not adding the fee to the original figure. The fee is
         * charged on the LARGER amount, so `amount + fee(amount)` always leaves
         * the foundation a little short of what the donor intended to give.
         */
        $charged = $coverFee ? $this->fees->grossUp($requested) : $requested;

        $designations = $this->resolveDesignations($input, $charged);

        return DB::transaction(function () use ($input, $charged, $coverFee, $designations): Donation {
            $donor = $this->resolveDonor($input);

            /** @var Cause $primary */
            $primary = $designations[0]['cause'];

            $donation = Donation::create([
                'donor_id' => $donor?->getKey(),
                'user_id' => $input['user_id'] ?? null,
                'cause_id' => $primary->getKey(),
                'division_id' => $primary->division_id,
                'amount' => $charged,
                'currency' => $charged->currency,
                'fee_covered_by_donor' => $coverFee,
                'status' => DonationStatus::Pending,
                'channel' => $input['channel'] ?? null,
                'momo_network' => $input['momo_network'] ?? null,
                'is_anonymous' => (bool) ($input['is_anonymous'] ?? false),
                /*
                 * What the donor ASKED for, not what happened. The subscription
                 * itself is established when the webhook confirms the money
                 * arrived — a standing order set up from a payment that was
                 * later declined is a monthly charge against a card that never
                 * worked.
                 */
                'wants_recurring' => (bool) ($input['wants_recurring'] ?? false),
                'recurring_interval' => ! empty($input['wants_recurring']) ? ($input['recurring_interval'] ?? Subscription::INTERVAL_MONTHLY) : null,
                'public_message' => $input['public_message'] ?? null,
                'source' => $input['source'] ?? null,
                'utm' => $input['utm'] ?? null,
                'momo_provider' => $input['momo_provider'] ?? null,
                'tribute_type' => $input['tribute']['type'] ?? null,
                'tribute_name' => $input['tribute']['name'] ?? null,
                'tribute_message' => $input['tribute']['message'] ?? null,
                'tribute_notify_email' => $input['tribute']['notify_email'] ?? null,
                // Snapshots, not joins: a donor can correct their details later,
                // and the acknowledgement already issued said what it said.
                'donor_name' => $input['donor_name'] ?? $donor?->name,
                'donor_email' => $input['donor_email'] ?? $donor?->email,
                // Normalised to +233…, so the same number typed as 024… and
                // +233 24… is one donor and one SMS destination.
                'donor_phone' => PhoneNumber::tryNormalise($input['donor_phone'] ?? null) ?? $donor?->phone,
                'consent_email' => (bool) ($input['consent_email'] ?? false),
                'consent_sms' => (bool) ($input['consent_sms'] ?? false),
                'consent_whatsapp' => (bool) ($input['consent_whatsapp'] ?? false),
                'consent_text' => $input['consent_text'] ?? null,
                'consent_ip' => $input['consent_ip'] ?? null,
                'consent_at' => isset($input['consent_text']) ? now() : null,
                'recorded_by' => $input['recorded_by'] ?? null,
            ]);

            foreach ($designations as $designation) {
                /** @var Cause $cause */
                $cause = $designation['cause'];

                DonationItem::create([
                    'donation_id' => $donation->getKey(),
                    'cause_id' => $cause->getKey(),
                    'division_id' => $cause->division_id,
                    'project_id' => $designation['project_id'] ?? $cause->project_id,
                    'amount' => $designation['amount'],
                    'currency' => $designation['amount']->currency,
                    'description' => $designation['description'] ?? null,
                    ...DonationItem::snapshotDeductibility($cause),
                ]);
            }

            // Before anything leaves. A gift whose parts do not sum to the whole
            // is a bug worth refusing to charge for.
            $donation->load('items')->assertItemsReconcile();
            $donation->recalculateDeductible();

            return $donation->refresh();
        });
    }

    /**
     * Build the gift and hand back the transaction to send the donor to.
     *
     * @param  array<string, mixed>  $input
     * @return array{donation: Donation, transaction: PaymentTransaction}
     */
    public function start(array $input): array
    {
        $donation = $this->create($input);

        $transaction = $this->payments->charge($donation, [
            'reference' => $donation->reference,
            'channel' => $input['channel'] ?? null,
            'callback_url' => $input['callback_url'] ?? null,
            /*
             * What the Paystack dashboard shows beside the payment. Ids and
             * slugs, never names or amounts of anything the gateway does not
             * already hold; attribution travels so a finance report from
             * Paystack's side can be joined to ours.
             */
            'metadata' => array_filter([
                'donation' => $donation->reference,
                'donation_id' => $donation->getKey(),
                'cause' => $donation->cause?->slug,
                'cause_id' => $donation->cause_id,
                'donor_id' => $donation->donor_id,
                'division' => $donation->division?->slug,
                'source' => $donation->source,
                'utm' => $donation->utm ?: null,
                'type' => 'donation',
            ], fn ($v) => $v !== null),
        ]);

        return ['donation' => $donation, 'transaction' => $transaction];
    }

    /**
     * Build the gift and charge a mobile-money wallet directly.
     *
     * No redirect: the donor stays on our page and approves a prompt on their
     * phone. The gift is created exactly as in `start()` — same validation,
     * same allocation, same fee handling — and only the way the money is
     * asked for differs. What comes back is the transaction with its waiting
     * state, or a settled or failed one where the network answered at once.
     *
     * @param  array<string, mixed>  $input  as start(), plus momo_provider and momo_phone
     * @return array{donation: Donation, transaction: PaymentTransaction}
     */
    public function startMobileMoney(array $input): array
    {
        $donation = $this->create(array_merge($input, ['channel' => 'mobile_money']));

        $transaction = $this->payments->chargeMobileMoney(
            $donation,
            (string) $input['momo_provider'],
            PhoneNumber::normalise((string) $input['momo_phone']),
            [
                'reference' => $donation->reference,
                'metadata' => array_filter([
                    'donation' => $donation->reference,
                    'donation_id' => $donation->getKey(),
                    'cause' => $donation->cause?->slug,
                    'cause_id' => $donation->cause_id,
                    'donor_id' => $donation->donor_id,
                    'source' => $donation->source,
                    'type' => 'donation',
                ], fn ($v) => $v !== null),
            ],
        );

        return ['donation' => $donation, 'transaction' => $transaction];
    }

    /**
     * Resolve where the money goes, and split it exactly.
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array{cause: Cause, amount: Money, project_id?: int|null, description?: string|null}>
     */
    private function resolveDesignations(array $input, Money $charged): array
    {
        $designations = $input['designations'] ?? null;

        if ($designations === null || $designations === []) {
            /*
             * No appeal chosen. The seeded General Fund is the fallback, and
             * `generalFund()` throws rather than returning null — a donation
             * with no destination is a payment the foundation has taken and
             * cannot account for.
             */
            $cause = $input['cause'] ?? Cause::generalFund();

            return [['cause' => $cause, 'amount' => $charged]];
        }

        $total = array_reduce(
            $designations,
            static fn (Money $carry, array $d): Money => $carry->plus($d['amount']),
            Money::zero($charged->currency),
        );

        /*
         * Split giving with a fee-covered gift: the donor chose how to split
         * the amount they intended, and the gross-up added a little on top.
         * That surplus is allocated across the same designations in proportion,
         * using Money::allocate(), which distributes the rounding remainder one
         * pesewa at a time so the parts still sum exactly to the whole.
         */
        if (! $total->equals($charged)) {
            $ratios = array_map(
                static fn (array $d): int => $d['amount']->toMinor(),
                $designations,
            );

            $allocated = $charged->allocate($ratios);

            foreach ($designations as $index => $designation) {
                $designations[$index]['amount'] = $allocated[$index];
            }
        }

        return array_values($designations);
    }

    /** @param array<string, mixed> $input */
    private function resolveDonor(array $input): ?Donor
    {
        if (isset($input['donor']) && $input['donor'] instanceof Donor) {
            return $input['donor'];
        }

        if (blank($input['donor_email'] ?? null) && blank($input['donor_phone'] ?? null)) {
            /*
             * A genuinely anonymous cash gift with no contact details. Allowed —
             * it happens at events — but it means no acknowledgement can be sent
             * and no giving history can be built.
             */
            return null;
        }

        return Donor::matchOrCreate([
            'name' => $input['donor_name'] ?? 'Anonymous donor',
            'email' => $input['donor_email'] ?? null,
            'phone' => $input['donor_phone'] ?? null,
            'address' => $input['donor_address'] ?? null,
            'city' => $input['donor_city'] ?? null,
            'consent_email' => $input['consent_email'] ?? false,
            'consent_sms' => $input['consent_sms'] ?? false,
            'consent_whatsapp' => $input['consent_whatsapp'] ?? false,
            'consent_text' => $input['consent_text'] ?? null,
            'consent_ip' => $input['consent_ip'] ?? null,
        ]);
    }

    /**
     * The floor exists because a gift smaller than the fee costs the foundation
     * money to accept. The ceiling is an anti-typo measure — a genuine large
     * gift is welcome, but an extra zero on a public form is one keystroke away,
     * and Finance should handle the real ones.
     */
    private function assertWithinLimits(Money $amount): void
    {
        $min = (int) config('payments.donations.min_minor', 500);
        $max = (int) config('payments.donations.max_minor', 10_000_000);

        if ($amount->toMinor() < $min) {
            throw new RuntimeException(sprintf(
                'The smallest donation this site accepts is %s.',
                Money::ofMinor($min, $amount->currency)->format(),
            ));
        }

        if ($max > 0 && $amount->toMinor() > $max) {
            throw new RuntimeException(sprintf(
                'Donations above %s are arranged with the foundation directly rather than online.',
                Money::ofMinor($max, $amount->currency)->format(),
            ));
        }
    }
}
