<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Shop\OrderDocuments;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The invoice, as a file.
 *
 * Reached through the signed link in the confirmation email or by the
 * account that placed the order — the same rule as a donation receipt,
 * because an invoice carries a name, an address and what was bought. Every
 * hand-over is audited as an export.
 */
class InvoiceController extends Controller
{
    public function download(Request $request, Invoice $invoice, OrderDocuments $documents): BinaryFileResponse
    {
        $ownsIt = $request->user() !== null
            && $invoice->order?->user_id !== null
            && $invoice->order->user_id === $request->user()->getKey();

        abort_unless($request->hasValidSignature() || $ownsIt, 403);

        app(AuditLogger::class)->recordExport('invoice.downloaded', 'shop invoice', 1, $request->user(), [
            'invoice' => $invoice->invoice_number,
            'signed' => $request->hasValidSignature(),
        ]);

        return response()->download($documents->invoicePath($invoice), $documents->invoiceFilename($invoice), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
