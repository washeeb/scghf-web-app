<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\DonationReceipt;
use App\Models\Media;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * The receipt as a document.
 *
 * ── Rendered from the RECEIPT ROW, never from the donation ──────────────────
 *
 * `ReceiptIssuer` froze everything the document will ever say onto the
 * receipt at issue: the donor's name as it was, the amount, the cause, the
 * acknowledgement paragraphs, which approval was cited. This renders that and
 * nothing else, so a reprint a year later is a transcription and not a
 * re-composition — the wording may have been revised since, and the document
 * in the donor's hands must keep saying what it said.
 *
 * ── DomPDF, because the host is shared ──────────────────────────────────────
 *
 * Pure PHP. The alternatives render through a headless browser, which
 * InMotion's shared hosting cannot run. The template is deliberately simple
 * HTML with inline styles — DomPDF's CSS support is a subset, and a receipt
 * is a page with a table on it.
 *
 * ── Cached on the private disk ──────────────────────────────────────────────
 *
 * Rendered once and kept, on the disk that is not web-reachable. The file is
 * served only through the signed download route, so a guessable path is not a
 * path anybody can fetch.
 */
final class ReceiptPdf
{
    // The `local` disk is storage/app/private: not under public_html, not web-reachable.
    private const DISK = 'local';

    /** The rendered file, rendering it if it has not been yet. */
    public function path(DonationReceipt $receipt): string
    {
        $path = 'receipts/'.$receipt->financial_year.'/'.$receipt->receipt_number.'.pdf';

        if (! Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->put($path, $this->render($receipt));
        }

        return Storage::disk(self::DISK)->path($path);
    }

    public function render(DonationReceipt $receipt): string
    {
        $logo = ($id = setting('header.logo_light')) ? Media::find($id) : null;

        return Pdf::loadView('pdf.receipt', [
            'receipt' => $receipt,
            'paragraphs' => $receipt->paragraphs(),
            'organisation' => [
                'name' => $receipt->organisation_name,
                'tin' => $receipt->organisation_tin,
                'registration' => (string) setting('general.registration_number'),
                'address' => collect([setting('contact.address'), setting('contact.city'), setting('contact.region')])->filter()->implode(', '),
                'email' => (string) setting('contact.email_donations', setting('contact.email_general')),
                'phone' => (string) setting('contact.phone_primary'),
                'website' => url('/'),
            ],
            'logoPath' => $logo?->isPublishable() ? $this->localPath($logo) : null,
        ])
            ->setPaper('a4')
            ->output();
    }

    /** A local file path for DomPDF, which cannot fetch a URL on this host. */
    private function localPath(Media $media): ?string
    {
        try {
            $path = $media->getPath();

            return is_file($path) ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function filename(DonationReceipt $receipt): string
    {
        return $receipt->receipt_number.'.pdf';
    }
}
