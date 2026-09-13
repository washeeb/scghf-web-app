<?php

declare(strict_types=1);

namespace App\Shop;

use App\Models\Invoice;
use App\Models\Media;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The paper of an order: the invoice and the packing slip.
 *
 * ── Two documents for two people ────────────────────────────────────────────
 *
 * The invoice is the customer's: goods, delivery, discount, total, in words,
 * with the separation line that says it is not a donation receipt. It is
 * rendered from the INVOICE ROW, frozen at issue, and cached on the private
 * disk under its number like a receipt.
 *
 * The packing slip is the packer's: what to put in the box, where it goes,
 * the phone number to call, the customer's notes — and no prices, because
 * a slip with prices in a box that goes to a gift recipient is a slip that
 * spoils a present. Never cached: it reflects the order as it is.
 *
 * ── Many slips, one PDF ─────────────────────────────────────────────────────
 *
 * A packing morning is a stack, not a click per parcel. `packingSlips()`
 * takes any number of orders and renders one document with a page break
 * between them, for the bulk action on the order list.
 */
final class OrderDocuments
{
    private const DISK = 'local';

    public function invoicePath(Invoice $invoice): string
    {
        $path = 'invoices/'.$invoice->financial_year.'/'.$invoice->invoice_number.'.pdf';

        if (! Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->put($path, $this->renderInvoice($invoice));
        }

        return Storage::disk(self::DISK)->path($path);
    }

    public function invoiceFilename(Invoice $invoice): string
    {
        return $invoice->invoice_number.'.pdf';
    }

    public function renderInvoice(Invoice $invoice): string
    {
        $invoice->loadMissing('order.items.product', 'order.shippingZone');

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'order' => $invoice->order,
            // Goods only. The gift lines are receipted, not invoiced.
            'lines' => $invoice->order->items->reject(fn ($item): bool => $item->product?->isDonation() ?? false),
            'organisation' => $this->organisation($invoice->organisation_name, $invoice->organisation_tin),
            'logoPath' => $this->logoPath(),
        ])->setPaper('a4')->output();
    }

    /** @param  Collection<int, Order>|iterable<Order>  $orders */
    public function packingSlips(iterable $orders): string
    {
        $orders = Collection::wrap($orders)->each(fn (Order $o) => $o->loadMissing('items.product', 'shippingZone'));

        return Pdf::loadView('pdf.packing-slips', [
            'orders' => $orders,
            'organisation' => $this->organisation((string) setting('general.legal_name'), null),
            'logoPath' => $this->logoPath(),
        ])->setPaper('a4')->output();
    }

    public function packingSlipFilename(Order $order): string
    {
        return 'packing-slip-'.$order->reference.'.pdf';
    }

    /** @return array<string, string|null> */
    private function organisation(string $name, ?string $tin): array
    {
        return [
            'name' => $name,
            'tin' => $tin,
            'registration' => (string) setting('general.registration_number'),
            'address' => collect([setting('contact.address'), setting('contact.city'), setting('contact.region')])->filter()->implode(', '),
            'email' => (string) setting('contact.email_shop', setting('contact.email_general')),
            'phone' => (string) setting('contact.phone_primary'),
            'website' => url('/'),
        ];
    }

    /** A local file path for DomPDF, which cannot fetch a URL on this host. */
    private function logoPath(): ?string
    {
        $logo = ($id = setting('header.logo_light')) ? Media::find($id) : null;

        if ($logo === null || ! $logo->isPublishable()) {
            return null;
        }

        try {
            $path = $logo->getPath();

            return is_file($path) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }
}
