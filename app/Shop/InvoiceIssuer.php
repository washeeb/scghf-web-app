<?php

declare(strict_types=1);

namespace App\Shop;

use App\Models\Invoice;
use App\Models\Order;
use App\Support\AmountInWords;
use App\Support\Settings;
use App\Support\TaxDeductibility;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues the sales receipt for a paid order.
 *
 * **This is the other half of the separation rule.** Donations get an
 * ACKNOWLEDGEMENT OF CONTRIBUTION on the `SCGHF-R-…` series; purchases get an
 * INVOICE on the `SCGHF-INV-…` series. The two never interleave, and this class
 * asserts before it writes anything that the order it is invoicing could not
 * lawfully receive a charitable acknowledgement instead.
 *
 * That assertion is not defensive padding. `Order` is on
 * `config('compliance.tax.never_acknowledge_payable_types')`, and checking it
 * here means the rule is enforced at the point a document is produced rather
 * than only where one is refused.
 */
final class InvoiceIssuer
{
    public function __construct(
        private readonly Settings $settings,
        private readonly TaxDeductibility $tax,
    ) {}

    /**
     * Issue, or return the invoice this order already has.
     *
     * Idempotent for the same reason an acknowledgement is: settlement can be
     * applied more than once, and each must not burn a second number.
     */
    public function issue(Order $order): Invoice
    {
        $existing = Invoice::where('order_id', $order->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! $order->status->isInvoiceable()) {
            throw new RuntimeException(sprintf(
                'Cannot invoice a %s order (%s). Only a paid order has money to receipt.',
                $order->status->value,
                $order->reference,
            ));
        }

        /*
         * The separation rule, asserted rather than assumed. If this ever
         * passes, something has gone wrong in the compliance config and a
         * purchase is about to be treated as a gift.
         */
        if ($this->tax->mayAcknowledge($order)) {
            throw new RuntimeException(
                'An Order is being treated as eligible for a charitable acknowledgement. '
                .'It is not: a purchase is consideration for goods. Check '
                .'compliance.tax.never_acknowledge_payable_types.'
            );
        }

        $order->loadMissing('items.product');

        if ($order->goodsTotal()->isZero()) {
            throw new RuntimeException(sprintf(
                'Order %s is gifts only; the donation receipt is its document and there is nothing to invoice.',
                $order->reference,
            ));
        }

        $year = (int) ($order->paid_at ?? now())->year;

        return DB::transaction(function () use ($order, $year): Invoice {
            $allocated = Invoice::allocateNumber($year);

            return Invoice::create([
                'order_id' => $order->getKey(),
                'invoice_number' => $allocated['invoice_number'],
                'financial_year' => $year,
                'sequence' => $allocated['sequence'],
                'issued_on' => now()->toDateString(),

                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'organisation_name' => (string) $this->settings->get(
                    'general.legal_name',
                    "St. Cecilia's Greater Hope Foundations",
                ),
                // Optional here, unlike on an acknowledgement. An invoice is a
                // sales document; it does not support anybody's tax claim, so a
                // missing TIN is untidy rather than disqualifying.
                'organisation_tin' => $this->settings->get('general.tin'),

                // Goods only. The gifts on the order are receipted, not invoiced.
                'subtotal' => $order->goodsSubtotal(),
                'shipping' => $order->shipping,
                'discount' => $order->discount,
                'total' => $order->goodsTotal(),
                'currency' => $order->currency,
                'total_in_words' => AmountInWords::money($order->goodsTotal()),

                'statement' => $this->statement(),
            ]);
        });
    }

    /**
     * The line that keeps the two documents apart in the customer's hands.
     *
     * Printed on every invoice, because the person holding it is the one most
     * likely to assume at tax time that money paid to a charity was a donation.
     * Saying so on the document is cheaper than explaining it afterwards.
     */
    public function statement(): string
    {
        return (string) $this->settings->get(
            'shop.invoice_statement',
            'This is a receipt for goods purchased. It is NOT an acknowledgement of a '
            .'charitable contribution and cannot be used to support a claim under section 100 '
            .'of the Income Tax Act, 2015 (Act 896). Proceeds from shop sales support the '
            .'foundation\'s work.',
        );
    }
}
