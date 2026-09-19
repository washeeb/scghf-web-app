<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GrantDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A grant's file, to staff who may see grants, on a signed link.
 */
class GrantDocumentController extends Controller
{
    public function download(Request $request, GrantDocument $document): BinaryFileResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $user = $request->user();
        abort_unless($user !== null && $user->can('view', $document), 403);

        $document->loadMissing('media');
        abort_if($document->media === null, 404);

        return response()->file($document->media->getPath(), [
            'Content-Type' => $document->media->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($document->media->file_name).'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
