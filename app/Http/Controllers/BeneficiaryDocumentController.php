<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Beneficiaries\CaseDocuments;
use App\Models\BeneficiaryDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The one way a case document leaves the server.
 *
 * Three checks, all required: signed in as staff; the URL's signature is
 * valid (five minutes, from CaseDocuments::link()); and the policy's
 * `download` says this person may open THIS document — a medical report
 * needs the case's view C, a school bill its view B. Then the audit row,
 * then the bytes. Served inline for a PDF or an image so it opens in the
 * browser tab rather than landing in Downloads by default — a smaller
 * footprint on a shared laptop, though nothing stops a save.
 */
class BeneficiaryDocumentController extends Controller
{
    public function download(Request $request, BeneficiaryDocument $document, CaseDocuments $documents): BinaryFileResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $user = $request->user();
        abort_unless($user !== null && $user->can('download', $document), 403);

        $document->loadMissing(['beneficiary', 'media']);
        abort_if($document->media === null, 404);

        $documents->recordDownload($document, $user);

        $inline = in_array($document->media->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);

        return response()->file($document->media->getPath(), [
            'Content-Type' => $document->media->mime_type,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes($document->media->file_name).'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
