<?php

declare(strict_types=1);

namespace App\Payments;

use App\Communications\MessageDispatcher;
use App\Models\Donation;
use App\Models\DonationReceipt;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Thanking the donor, once the money is actually in.
 *
 * ── The thank-you page promised a receipt that nothing sent ─────────────────
 *
 * `donation.receipt` has been seeded since Phase 3, `ReceiptIssuer` has
 * composed acknowledgements since Phase 4, and a gift confirmed by the webhook
 * triggered neither: the receipt row was never allocated and no email left.
 * The page said "a receipt is on its way to you" over a promise the
 * application could not keep.
 *
 * ── The receipt is issued first, and the email carries its wording ─────────
 *
 * The acknowledgement paragraphs are composed by `ReceiptIssuer` from the
 * compliance policy — whether the gift supports a tax claim, which approval it
 * cites — and frozen onto the receipt row. The email prints THOSE paragraphs,
 * so what the donor reads is what the document says. Phase 8 attaches the PDF
 * to this same message; nothing here has to change for that.
 *
 * ── If the receipt cannot be issued, no email is sent ───────────────────────
 *
 * The issuer refuses when the foundation's TIN is unfilled, because an
 * acknowledgement without one is a document a tax authority would reject.
 * Sending a thank-you that claims to be a receipt but is not would be worse
 * than sending nothing — the refusal is reported, Site Health already flags
 * the missing TIN, and the receipt is issued by hand or on the next settlement
 * pass once it is filled.
 *
 * ── Idempotent, and never thrown into the payment path ─────────────────────
 *
 * A replayed webhook calls this again. The issuer returns the receipt it
 * already has; the outbox refuses a second message on the idempotency key.
 * Everything is reported rather than rethrown, because the money has moved
 * and a broken template must not have the gateway redeliver a counted gift.
 */
final class DonationNotifier
{
    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly ReceiptIssuer $receipts,
    ) {}

    public function thank(Donation $donation): void
    {
        if (! $donation->status->isReceiptable()) {
            return;
        }

        $receipt = null;

        try {
            $receipt = $this->receipts->issue($donation);
        } catch (Throwable $e) {
            report($e);
        }

        if ($receipt !== null && filled($donation->donor_email)) {
            try {
                $this->email($donation, $receipt);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (filled($donation->donor_phone)) {
            try {
                $this->sms($donation);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function email(Donation $donation, DonationReceipt $receipt): void
    {
        $paragraphs = collect($receipt->paragraphs())
            ->map(fn (string $p): string => '<p>'.e($p).'</p>')
            ->implode("\n");

        $this->dispatcher->queueEmail('donation.receipt', (string) $donation->donor_email, [
            'donor_name' => $receipt->donor_name,
            'amount' => $donation->amount,
            'amount_in_words' => $receipt->amount_in_words,
            'reference' => $donation->reference,
            'receipt_number' => $receipt->receipt_number,
            'cause_name' => $receipt->cause,
            'donation_date' => $receipt->donated_on,
            'acknowledgement' => new HtmlString($paragraphs),
        ], [
            'to_name' => $receipt->donor_name,
            'related' => $donation,
            'user_id' => $donation->user_id,
            'idempotency_key' => 'donation.receipt:'.$donation->reference,
        ]);

        $receipt->markSent((string) $donation->donor_email);
    }

    /**
     * The one-line confirmation.
     *
     * Transactional, not marketing — it confirms a payment the donor just
     * made, which is why it does not wait on `consent_sms`. For a mobile-money
     * donor it is often the only confirmation they see.
     */
    private function sms(Donation $donation): void
    {
        $this->dispatcher->queueSms('donation.received', (string) $donation->donor_phone, [
            // "GHS 50.00", not "GH₵": the cedi sign is not in the GSM-7
            // alphabet and would triple the cost of the message.
            'amount' => $donation->amount->toMajorString(),
            'reference' => $donation->reference,
        ], [
            'related' => $donation,
            'user_id' => $donation->user_id,
            'idempotency_key' => 'donation.received:'.$donation->reference,
        ]);
    }
}
