<?php

declare(strict_types=1);

namespace App\Payments;

use App\Communications\MessageDispatcher;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Support\Features;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\URL;
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

        if ($donation->consent_whatsapp && filled($donation->donor_phone) && app(Features::class)->enabled('whatsapp')) {
            try {
                $this->whatsapp($donation, $receipt);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (filled($donation->tribute_notify_email)) {
            try {
                $this->tribute($donation);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $this->alertStaffIfLarge($donation);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A gift big enough that somebody should know today.
     *
     * The line is Settings → Email & SMS → "tell me about a gift of at
     * least"; zero means never. To the alerts address, once per gift.
     */
    private function alertStaffIfLarge(Donation $donation): void
    {
        $line = setting('communications.new_donation_alert_minor', 0);
        $line = $line instanceof Money ? $line->toMinor() : (int) $line;
        $to = (string) (setting('communications.alert_email') ?: setting('contact.email_general', ''));

        if ($line <= 0 || $donation->amount->toMinor() < $line || $to === '' || str_contains($to, '{{')) {
            return;
        }

        $this->dispatcher->queueEmail('admin.new_donation', $to, [
            'amount' => $donation->amount,
            'donor_name' => $donation->is_anonymous ? __('An anonymous donor') : ($donation->donor_name ?: __('A donor')),
            'cause_name' => $donation->cause?->title ?? __('the General Fund'),
            'reference' => $donation->reference,
            'admin_url' => route('filament.admin.resources.donations.view', $donation),
        ], [
            'related' => $donation,
            'idempotency_key' => 'admin.new_donation:'.$donation->reference,
        ]);
    }

    /**
     * Tell the person the donor named that a gift was made in tribute.
     *
     * The form promised "never how much", so the amount is not a variable
     * the template can reach. An anonymous donor is "Someone". Sent once per
     * gift, after the money is in — a tribute for a payment that failed is
     * a message nobody should receive.
     */
    private function tribute(Donation $donation): void
    {
        $this->dispatcher->queueEmail('donation.tribute', (string) $donation->tribute_notify_email, [
            'donor_name' => $donation->is_anonymous ? __('Someone') : ($donation->donor_name ?: __('A donor')),
            'tribute_kind' => $donation->tribute_type === 'memory' ? __('in memory of') : __('in honour of'),
            'tribute_name' => (string) $donation->tribute_name,
            'tribute_message' => (string) ($donation->tribute_message ?? ''),
            'cause_name' => $donation->cause?->title ?? __('our work'),
        ], [
            'related' => $donation,
            'idempotency_key' => 'donation.tribute:'.$donation->reference,
        ]);
    }

    /**
     * Send the acknowledgement again, on request.
     *
     * A fresh idempotency key each time, so the outbox does not refuse it as
     * the duplicate of the one sent at settlement — which is what the donor
     * who says it never arrived is asking for.
     */
    public function resend(Donation $donation): void
    {
        $receipt = $donation->receipt;

        if ($receipt === null || blank($donation->donor_email)) {
            return;
        }

        $this->email($donation, $receipt, 'donation.receipt:'.$donation->reference.':'.now()->timestamp);
    }

    /**
     * The payment did not go through, and nothing was taken.
     *
     * The single most useful email a failed payment can produce: it says
     * plainly that no money left, and it carries a link that brings the donor
     * back to the form with the same amount and appeal filled in. Transactional
     * — it reports on something the donor just did.
     */
    public function failed(Donation $donation): void
    {
        if (blank($donation->donor_email)) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('donation.failed', (string) $donation->donor_email, [
                'donor_name' => $donation->donor_name ?: __('friend'),
                'amount' => $donation->amount,
                'reference' => $donation->reference,
                'retry_url' => $this->retryUrl($donation),
            ], [
                'to_name' => $donation->donor_name,
                'related' => $donation,
                'user_id' => $donation->user_id,
                'idempotency_key' => 'donation.failed:'.$donation->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The donor opened the payment page and never came back.
     *
     * ⚠ OFF unless `donations.abandoned_followup` is switched on. A reminder
     * to somebody who decided not to give is the kind of email that makes a
     * foundation look desperate, and the trustees should choose it rather than
     * have it chosen for them. Sent once, an hour or more after the fact
     * (the reconciliation sweep decides when a payment counts as abandoned),
     * and only to a donor who consented to email.
     */
    public function abandoned(Donation $donation): void
    {
        if (! setting('donations.abandoned_followup', false) || blank($donation->donor_email) || ! $donation->consent_email) {
            return;
        }

        try {
            $this->dispatcher->queueEmail('donation.abandoned', (string) $donation->donor_email, [
                'donor_name' => $donation->donor_name ?: __('friend'),
                'amount' => $donation->amount,
                'cause_name' => $donation->cause?->title,
                'retry_url' => $this->retryUrl($donation),
            ], [
                'to_name' => $donation->donor_name,
                'related' => $donation,
                'user_id' => $donation->user_id,
                'idempotency_key' => 'donation.abandoned:'.$donation->reference,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Back to the form, with the amount and appeal filled in. */
    private function retryUrl(Donation $donation): string
    {
        return route('donate', array_filter([
            'amount' => $donation->amount->toMajorString(),
            'cause' => $donation->cause !== null && ! $donation->cause->is_general_fund ? $donation->cause->slug : null,
            'source' => 'retry',
        ]));
    }

    private function email(Donation $donation, DonationReceipt $receipt, ?string $idempotencyKey = null): void
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
            // Signed and dated: the receipt names a person and an amount, and
            // this link will be forwarded. Ninety days, then the account area.
            'receipt_url' => URL::temporarySignedRoute('receipts.download', now()->addDays(90), ['receipt' => $receipt->ulid]),
        ], [
            'to_name' => $receipt->donor_name,
            'related' => $donation,
            'user_id' => $donation->user_id,
            'idempotency_key' => $idempotencyKey ?? 'donation.receipt:'.$donation->reference,
        ]);

        $receipt->markSent((string) $donation->donor_email);
    }

    /**
     * The receipt on WhatsApp (Wave 2), for a donor who ticked the box.
     *
     * A Meta-approved template with the amount, the reference and the
     * appeal; the PDF stays on the email and the thank-you page. Queued
     * only when the flag is on and the template is approved — otherwise
     * `queueWhatsapp()` refuses and the refusal is reported, not swallowed.
     */
    private function whatsapp(Donation $donation, ?DonationReceipt $receipt): void
    {
        $this->dispatcher->queueWhatsapp('donation.receipt', (string) $donation->donor_phone, [
            'name' => (string) ($donation->donor_name ?: __('Friend')),
            'amount' => 'GHS '.$donation->amount->toMajorString(),
            'reference' => $donation->reference,
            'cause' => $donation->cause ? (string) $donation->cause->title : __('the General Fund'),
            'receipt_number' => $receipt ? (string) $receipt->receipt_number : $donation->reference,
        ], [
            'related' => $donation,
            'user_id' => $donation->user_id,
            'idempotency_key' => 'donation.receipt.whatsapp:'.$donation->reference,
        ]);
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
