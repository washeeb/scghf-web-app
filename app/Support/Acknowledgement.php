<?php

declare(strict_types=1);

namespace App\Support;

use App\ValueObjects\Money;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Composes the donor acknowledgement document.
 *
 * The document is an ACKNOWLEDGEMENT OF CONTRIBUTION/DONATION TO A WORTHWHILE
 * CAUSE, not a "tax-deductible receipt". That naming is the whole point: under
 * Act 896 s.100 the donor claims the deduction on their own return, supported by
 * a written acknowledgement from a verifiable beneficiary. The Foundation
 * acknowledges the gift; the GRA decides the deduction. A document titled
 * "tax-deductible receipt" asserts the second thing, which is not the
 * Foundation's to assert.
 *
 * The document is built in three paragraphs:
 *
 *   1. the Foundation's s.97 approved status — ONLY while a valid Notice of
 *      Approval is held on the date of the donation
 *   2. the acknowledgement of receipt itself — always
 *   3. the s.100 purpose and the GRA-determination disclaimer — only alongside
 *      paragraph 1, because it is meaningless without it
 *
 * A donation received when no approval was held still produces a document.
 * It simply produces a plain receipt with no tax wording anywhere in it, rather
 * than nothing at all — the donor is still entitled to evidence of their gift.
 *
 * @see TaxDeductibility the single gate this class asks; it decides nothing itself
 */
final class Acknowledgement
{
    public function __construct(
        private readonly TaxDeductibility $tax,
        private readonly Settings $settings,
    ) {}

    /**
     * Build the document for one donation.
     *
     * @param  array<string, mixed>  $context  {
     *
     * @var string $receipt_number     unique acknowledgement number
     * @var string $donor_name         as it should appear on the document
     * @var Money $amount             the gift, in GHS
     * @var DateTimeInterface $donated_on         when the gift was received
     * @var string $cause              worthwhile cause: division, project or campaign
     * @var string $payment_reference  Paystack or internal reference
     * @var object|null $cause_model        the cause record, for the s.100 qualification test
     * @var object|null $payable            the thing paid for, for the shop-order prohibition
     * @var DateTimeInterface|null $issued_on          defaults to today
     * @var string|null $authentication     authorised signatory name or seal reference
     *                  }
     *
     * @return array{
     *     title: string,
     *     cites_approval: bool,
     *     paragraphs: array<int, string>,
     *     fields: array<string, string>,
     *     approval: array{reference: string, validity: string}|null
     * }
     */
    public function for(array $context): array
    {
        $payable = $context['payable'] ?? null;

        /*
         * The shop prohibition, checked first and unconditionally. A purchase is
         * consideration for goods, not a gift; acknowledging one as a charitable
         * contribution would misstate the transaction to the customer and to the
         * GRA. Throwing rather than returning null because reaching here with an
         * Order is a programming error, and a silent null would show up as a
         * missing document rather than as the bug it is.
         */
        if ($payable !== null && ! $this->tax->mayAcknowledge($payable)) {
            throw new RuntimeException(
                'A charitable acknowledgement can never be issued for a '.$payable::class.'. '
                .'Shop purchases receive a sales receipt, on a separate numbering series.'
            );
        }

        $amount = $context['amount'] ?? throw new RuntimeException('An acknowledgement needs an amount.');

        if (! $amount instanceof Money) {
            throw new RuntimeException('The amount must be a Money, so the figures and the words agree.');
        }

        $donatedOn = Carbon::instance($context['donated_on']);
        $issuedOn = isset($context['issued_on']) ? Carbon::instance($context['issued_on']) : now();

        $organisation = $this->settings->get('general.legal_name');
        $tin = $this->settings->get('general.tin');

        // Evaluated against the DONATION date, not today. See
        // TaxDeductibility::approvalOn() for why that distinction matters.
        $citesApproval = $this->tax->qualifies($context['cause_model'] ?? null, $donatedOn);
        $approval = $citesApproval ? $this->tax->approvalOn($donatedOn) : null;

        // qualifies() can only be true with an approval behind it, but a null
        // here would put an unfilled ":reference" onto a legal document, so the
        // two are re-tied rather than assumed consistent.
        if ($citesApproval && $approval === null) {
            $citesApproval = false;
        }

        $words = AmountInWords::money($amount);

        $paragraphs = [];

        if ($citesApproval) {
            $paragraphs[] = $approval->approvalParagraph((string) $organisation);
        }

        $paragraphs[] = strtr((string) config('compliance.tax.acknowledgement.receipt_paragraph'), [
            ':donor' => (string) $context['donor_name'],
            ':amount' => $amount->format(),
            ':amount_in_words' => $words,
            ':date' => $donatedOn->format('j F Y'),
            ':cause' => (string) $context['cause'],
        ]);

        if ($citesApproval) {
            $paragraphs[] = (string) config('compliance.tax.acknowledgement.disclaimer');
        }

        $fields = [
            'receipt_number' => (string) ($context['receipt_number'] ?? ''),
            'issued_on' => $issuedOn->format('j F Y'),
            'donor_name' => (string) ($context['donor_name'] ?? ''),
            'organisation_name' => (string) $organisation,
            'organisation_tin' => (string) $tin,
            'amount' => $amount->format(),
            'amount_in_words' => $words,
            'donated_on' => $donatedOn->format('j F Y'),
            'cause' => (string) ($context['cause'] ?? ''),
            'payment_reference' => (string) ($context['payment_reference'] ?? ''),
            'approval_reference' => $approval?->reference ?? '',
            'approval_validity' => $approval?->validityStatement() ?? '',
            'authentication' => (string) ($context['authentication'] ?? ''),
        ];

        $this->assertComplete($fields, $citesApproval);

        return [
            'title' => (string) config('compliance.tax.acknowledgement.title'),
            'cites_approval' => $citesApproval,
            'paragraphs' => $paragraphs,
            'fields' => $fields,
            'approval' => $approval === null ? null : [
                'reference' => $approval->reference,
                'validity' => $approval->validityStatement(),
            ],
        ];
    }

    /**
     * Refuse to issue a document with holes in it.
     *
     * The GRA's own claim form asks the donor for the worthwhile cause, the
     * beneficiary, the beneficiary's TIN and the amount, and requires this
     * acknowledgement to accompany the application. A blank where the TIN should
     * be is not a cosmetic problem — it is a document the donor cannot use.
     *
     * Note this is where an unfilled `{{TIN}}` placeholder surfaces: Settings
     * returns null for an unfilled placeholder, so acknowledgements refuse to
     * issue until the Foundation's real TIN is entered. That is deliberate.
     *
     * @param  array<string, string>  $fields
     */
    private function assertComplete(array $fields, bool $citesApproval): void
    {
        $required = (array) config('compliance.tax.acknowledgement.required_fields', []);

        // The two approval fields are required only when the document actually
        // cites an approval. On a plain receipt there is no approval to state,
        // and demanding one would make an honest document impossible to issue.
        if (! $citesApproval) {
            unset($required['approval_reference'], $required['approval_validity']);
        }

        $missing = [];

        foreach ($required as $key => $label) {
            if (trim($fields[$key] ?? '') === '') {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Cannot issue an acknowledgement without: '.implode(', ', $missing).'. '
                .'A legal document handed to a tax authority does not get blank lines.'
            );
        }
    }
}
