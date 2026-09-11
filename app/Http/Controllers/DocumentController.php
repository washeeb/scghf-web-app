<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Support\PageMeta;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reports, policies and financial statements.
 *
 * ── This page is a trust signal, not a filing cabinet ───────────────────────
 *
 * A Ghanaian non-profit asking the public for money is expected to publish its
 * accounts, its annual report and its safeguarding policy. A donor deciding
 * whether to trust a payment form looks for exactly those, and their absence is
 * what a scam site has in common with a real one that never got round to it.
 *
 * ── `requires_auth` is a gate, not a label ──────────────────────────────────
 *
 * The column has existed since Phase 3 with nothing enforcing it. A restricted
 * document is served through this controller, which checks, rather than through
 * a public storage URL that anybody with the address can fetch — and a storage
 * URL, once issued, is a string that ends up in a browser history and in every
 * referrer header the next page sends.
 */
class DocumentController extends Controller
{
    public function index(): View
    {
        $documents = Document::query()
            ->where('is_published', true)
            /*
             * A restricted document is not listed publicly either. Listing it
             * advertises the existence of an internal policy to everybody, and
             * with a safeguarding document the title is sometimes the sensitive
             * part.
             */
            ->where('requires_auth', false)
            ->with('media')
            ->orderByDesc('year')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('document_type');

        return view('documents.index', [
            'documents' => $documents,
            'meta' => PageMeta::site(
                __('Reports & policies'),
                __('Our annual reports, financial statements and the policies we work to.'),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Reports & policies'), 'url' => null],
            ],
        ]);
    }

    /**
     * Hand over the file, and count it.
     *
     * `download_count` has been on the table since Phase 3 and incremented by
     * nothing. It is the only signal the foundation has for which of its
     * publications anybody actually reads — which is what decides whether next
     * year's annual report is worth the design budget.
     */
    public function download(Request $request, Document $document): Response
    {
        if (! $document->is_published || $document->media === null) {
            throw new NotFoundHttpException;
        }

        if (! $document->isDownloadableBy($request->user())) {
            /*
             * A 404, not a 403. The listing already hides restricted documents;
             * confirming that one exists at a guessed address would undo that
             * for anybody who bothered to try.
             */
            throw new NotFoundHttpException;
        }

        $document->recordDownload();

        return response()->redirectTo($document->media->getUrl());
    }
}
