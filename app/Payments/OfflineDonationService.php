<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Models\Donation;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records a gift that arrived without the website: cash at an event, a cheque,
 * a bank transfer.
 *
 * It goes into the SAME ledger as an online gift, counts towards the same cause
 * total, and gets an acknowledgement with a number from the same series. A
 * parallel table for offline giving would mean every report had to union two
 * sources, and the one that forgot would be the one shown to a trustee.
 *
 * The differences are all about evidence rather than structure:
 *
 *   - no gateway, so a transaction row is written with `gateway = offline` to
 *     keep the payment path uniform
 *   - no fee, because nobody took one
 *   - `received_on` is when the money arrived, which is not when somebody got
 *     round to entering it
 *   - `recorded_by` is required: cash entered by nobody is how cash goes missing
 */
final class OfflineDonationService
{
    public const METHOD_CASH = 'cash';

    public const METHOD_CHEQUE = 'cheque';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_IN_KIND = 'in_kind';

    public function __construct(
        private readonly DonationService $donations,
        private readonly ReceiptIssuer $receipts,
    ) {}

    /**
     * Record an offline gift as completed, and acknowledge it.
     *
     * @param  array<string, mixed>  $input  the DonationService shape, plus
     *                                       `offline_method`, `offline_reference`
     *                                       and `received_on`
     */
    public function record(array $input, User $recordedBy): Donation
    {
        $method = (string) ($input['offline_method'] ?? self::METHOD_CASH);

        $this->assertMethodIsKnown($method);

        if ($method === self::METHOD_CHEQUE && blank($input['offline_reference'] ?? null)) {
            throw new RuntimeException(
                'A cheque gift needs the cheque number. It is what Finance matches against the '
                .'bank statement, and without it the gift cannot be reconciled.'
            );
        }

        $receivedOn = isset($input['received_on'])
            ? Carbon::parse((string) $input['received_on'])
            : now();

        if ($receivedOn->isFuture()) {
            throw new RuntimeException('A gift cannot have been received in the future.');
        }

        return DB::transaction(function () use ($input, $method, $receivedOn, $recordedBy): Donation {
            $donation = $this->donations->create([
                ...$input,
                'channel' => 'offline',
                'recorded_by' => $recordedBy->getKey(),
            ]);

            /*
             * A transaction row even though no gateway was involved. One payment
             * path for everything means reconciliation, refunds and reporting
             * do not each need an "unless it was cash" branch.
             */
            $transaction = PaymentTransaction::create([
                'payable_type' => $donation->getMorphClass(),
                'payable_id' => $donation->getKey(),
                'gateway' => PaymentTransaction::GATEWAY_OFFLINE,
                'gateway_reference' => 'OFFLINE-'.$donation->reference,
                'amount' => $donation->amount,
                'currency' => $donation->currency,
                'status' => PaymentStatus::Pending,
                /*
                 * `offline`, not the specific method. `channel` means the same
                 * thing on every transaction — how the money came in at the
                 * gateway level — and settlement copies it onto the donation.
                 * Putting `cash` here would overwrite the donation's channel
                 * and make an offline gift look like a payment method the
                 * gateway supports. The method lives on `offline_method` and in
                 * the settlement payload.
                 */
                'channel' => 'offline',
                'customer_email' => $donation->donor_email,
                'initialised_at' => now(),
            ]);

            // Settled at face value: the money is already in hand, and nobody
            // took a fee. This still goes through settle(), so the amount check
            // that protects every other payment protects this one too.
            $transaction->settle(
                amountPaid: $donation->amount,
                paidAt: $receivedOn,
                payload: ['offline' => true, 'method' => $method],
                fee: Money::zero($donation->currency),
            );

            $donation->forceFill([
                'offline_method' => $method,
                'offline_reference' => $input['offline_reference'] ?? null,
                'received_on' => $receivedOn->toDateString(),
            ])->save();

            $donation->onPaymentSettled($transaction->refresh());

            return $donation->refresh();
        });
    }

    /**
     * Record it and issue the acknowledgement.
     *
     * Separate from `record()` so a bulk import can write a hundred gifts and
     * acknowledge them afterwards, rather than rendering a hundred documents
     * inside one transaction.
     */
    public function recordAndAcknowledge(array $input, User $recordedBy): Donation
    {
        $donation = $this->record($input, $recordedBy);

        $this->receipts->issue($donation, $recordedBy);

        return $donation->refresh();
    }

    private function assertMethodIsKnown(string $method): void
    {
        $known = [
            self::METHOD_CASH, self::METHOD_CHEQUE,
            self::METHOD_BANK_TRANSFER, self::METHOD_IN_KIND,
        ];

        if (! in_array($method, $known, true)) {
            throw new RuntimeException(
                "Unknown offline method [{$method}]. Use one of: ".implode(', ', $known).'.'
            );
        }
    }
}
