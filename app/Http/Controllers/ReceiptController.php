<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DonationReceipt;
use App\Payments\ReceiptPdf;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloading a receipt.
 *
 * ── Signed, and reached by ULID ─────────────────────────────────────────────
 *
 * A receipt names a person and an amount. The link is signed and expires, so
 * it can be put in an email and on the thank-you page without either being a
 * URL anybody could construct — and the ULID means the receipts table cannot
 * be walked by counting. A signed-in donor may also fetch their own receipts
 * from the account area without a signature.
 *
 * ── Every download is recorded ─────────────────────────────────────────────
 *
 * Through `AuditLogger::recordExport()`, the same as a CSV leaving the admin:
 * it is personal data leaving the application.
 */
class ReceiptController extends Controller
{
    public function download(Request $request, DonationReceipt $receipt, ReceiptPdf $pdf): BinaryFileResponse
    {
        $ownsIt = $request->user() !== null
            && $receipt->donation?->user_id !== null
            && $receipt->donation->user_id === $request->user()->getKey();

        abort_unless($request->hasValidSignature() || $ownsIt, 403);

        app(AuditLogger::class)->recordExport('receipt.downloaded', 'donation receipt', 1, $request->user(), [
            'receipt' => $receipt->receipt_number,
            'signed' => $request->hasValidSignature(),
        ]);

        return response()->download($pdf->path($receipt), $pdf->filename($receipt), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
