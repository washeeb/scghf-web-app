<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\User;
use App\Support\Acknowledgement;
use App\Support\AmountInWords;
use App\Support\Settings;
use App\Support\TaxDeductibility;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues the acknowledgement for a completed gift.
 *
 * Everything the document will ever say is composed here and frozen onto the
 * row. Nothing is re-rendered later: the approval cited can lapse, the wording
 * can be revised, and the trustees can change which causes qualify — and a
 * document already in a donor's hands must keep saying what it said.
 *
 * Four refusals, all of them deliberate:
 *
 *   - a gift that is not completed gets no receipt. Pending, failed and
 *     needs-review gifts are not gifts yet.
 *   - a payable on the never-acknowledge list gets none either. A shop purchase
 *     is consideration for goods, not a contribution, and acknowledging one as
 *     a charitable gift would misstate the transaction to the customer and the
 *     GRA.
 *   - a missing Foundation TIN stops issue entirely. Settings reports an
 *     unfilled {{TIN}} placeholder as absent, so acknowledgements refuse rather
 *     than printing a blank line on a document destined for a tax authority.
 *   - a second receipt for the same gift is refused by a unique index, not by
 *     a check somebody has to remember.
 */
final class ReceiptIssuer
{
    public function __construct(
        private readonly Acknowledgement $acknowledgement,
        private readonly TaxDeductibility $tax,
        private readonly Settings $settings,
    ) {}

    /**
     * Issue, or return the receipt this gift already has.
     *
     * Idempotent on purpose. Settlement can be applied more than once — a
     * retried webhook, a reconciliation run, an administrator re-checking by
     * hand — and each of those must not burn a second number in the series.
     */
    public function issue(Donation $donation, ?User $by = null): DonationReceipt
    {
        // Explicit, so a donation that arrived in a collection (a bulk
        // issue, a script) does not trip strict Eloquent's lazy-load guard on
        // the relations the document reads. A single fresh model is unaffected.
        $donation->loadMissing(['cause', 'donor', 'items.cause']);

        $existing = DonationReceipt::where('donation_id', $donation->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! $donation->status->isReceiptable()) {
            throw new RuntimeException(sprintf(
                'Cannot issue an acknowledgement for a %s donation (%s). Only a completed gift '
                .'has actually been received.',
                $donation->status->value,
                $donation->reference,
            ));
        }

        $year = (int) ($donation->paid_at ?? now())->year;

        return DB::transaction(function () use ($donation, $year, $by): DonationReceipt {
            /*
             * The number is allocated FIRST, under a row lock, so the document
             * can be composed carrying its own real reference rather than a
             * placeholder. Anything that throws after this point — a missing
             * TIN, a failed write — rolls the allocation back and returns the
             * number to the series, which is the whole reason it is a counter
             * row rather than an AUTO_INCREMENT.
             */
            $allocated = DonationReceipt::allocateNumber($year);

            $document = $this->acknowledgement->for(
                $this->context($donation, $allocated['receipt_number'])
            );

            $approval = $document['cites_approval'] ? $this->tax->approvalOn($donation->paid_at) : null;

            return DonationReceipt::create([
                'donation_id' => $donation->getKey(),
                'receipt_number' => $allocated['receipt_number'],
                'financial_year' => $year,
                'sequence' => $allocated['sequence'],
                'issued_on' => now()->toDateString(),

                'donor_name' => $document['fields']['donor_name'],
                'donor_email' => $donation->donor_email,
                'organisation_name' => $document['fields']['organisation_name'],
                'organisation_tin' => $document['fields']['organisation_tin'],

                'amount' => $donation->amount,
                'deductible_amount' => $donation->deductibleAmount(),
                'non_deductible_amount' => $donation->nonDeductibleAmount(),
                'currency' => $donation->currency,
                'amount_in_words' => $document['fields']['amount_in_words'],

                'cause' => $document['fields']['cause'],
                'payment_reference' => $donation->paystack_reference ?? $donation->reference,
                'donated_on' => ($donation->paid_at ?? now())->toDateString(),

                'cites_approval' => $document['cites_approval'],
                'tax_approval_id' => $approval?->getKey(),
                'approval_reference' => $document['approval']['reference'] ?? null,
                'approval_validity' => $document['approval']['validity'] ?? null,

                // The paragraphs exactly as issued, kept as JSON so a reprint is
                // a transcription rather than a re-render.
                'statement' => json_encode($document['paragraphs'], JSON_THROW_ON_ERROR),

                'authentication' => $document['fields']['authentication'],
                'issued_by' => $by?->getKey(),
            ]);
        });
    }

    /**
     * Everything the acknowledgement builder needs.
     *
     * The deductible SUBTOTAL is what the tax wording covers, not the gross —
     * a GH₵ 500 gift split GH₵ 300 deductible and GH₵ 200 not is evidence for a
     * claim on GH₵ 300. Passing the gross would overstate it on a document the
     * donor submits to the GRA.
     *
     * @return array<string, mixed>
     */
    private function context(Donation $donation, string $receiptNumber): array
    {
        $deductible = $donation->deductibleAmount();
        $usesDeductible = $deductible->isPositive();

        return [
            'receipt_number' => $receiptNumber,
            'donor_name' => $donation->donor_name ?? $donation->donor?->displayName() ?? 'A supporter',
            'amount' => $usesDeductible ? $deductible : $donation->amount,
            'amount_in_words' => AmountInWords::money($usesDeductible ? $deductible : $donation->amount),
            'donated_on' => $donation->paid_at ?? now(),
            'cause' => $this->causeLabel($donation),
            'payment_reference' => $donation->paystack_reference ?? $donation->reference,
            'authentication' => $this->settings->get(
                'general.receipt_signatory',
                'Authorised signatory, for and on behalf of the Board of Trustees',
            ),
            // Only a cause the gift actually has a deductible item for may drive
            // the tax wording.
            'cause_model' => $usesDeductible ? $donation->cause : null,
            'payable' => $donation,
        ];
    }

    /**
     * How the destination is described on the document.
     *
     * The GRA's claim form asks for the worthwhile cause, so a split gift names
     * every destination rather than only the first — the donor has to be able
     * to answer the question the form asks.
     */
    private function causeLabel(Donation $donation): string
    {
        $items = $donation->items()->with('cause')->get();

        if ($items->count() <= 1) {
            return (string) ($donation->cause?->title ?? 'General Fund');
        }

        return $items
            ->map(fn ($item): string => (string) ($item->cause?->title ?? 'General Fund'))
            ->unique()
            ->implode('; ');
    }
}
