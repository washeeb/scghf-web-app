<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\DigitalDownloadToken;
use App\Support\PageMeta;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Handing over a paid file.
 *
 * ── The token is the ticket ─────────────────────────────────────────────────
 *
 * Sixty-four random characters, issued when the order is paid, expiring
 * after the product's number of days and refusing after its number of uses.
 * The file itself is on the `downloads` disk, which nothing serves: the only
 * way to it is through here, and every hand-over is counted with the address
 * it went to.
 *
 * ── A refusal is a page, not a 403 ──────────────────────────────────────────
 *
 * The person holding an expired link paid for the file. They get a sentence
 * saying why it stopped working and what to do, not an error code.
 */
class DownloadController extends Controller
{
    public function show(Request $request, DigitalDownloadToken $token): BinaryFileResponse|View
    {
        $token->load(['order', 'item', 'media']);

        if ($token->media === null) {
            throw new NotFoundHttpException;
        }

        if ($reason = $token->rejectionReason()) {
            return view('shop.download-refused', [
                'reason' => $reason,
                'token' => $token,
                'meta' => PageMeta::site(__('Download'), noindex: true),
                'crumbs' => [
                    ['label' => __('Home'), 'url' => url('/')],
                    ['label' => __('Download'), 'url' => null],
                ],
            ]);
        }

        $token->recordDownload($request->ip());

        return response()->download(
            $token->media->getPath(),
            $token->media->file_name,
            ['Content-Type' => $token->media->mime_type],
        );
    }
}
