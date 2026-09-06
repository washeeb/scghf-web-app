<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Gallery;
use App\Support\PageMeta;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Photograph albums.
 *
 * ── Consent gates this twice, and both gates are load-bearing ──────────────
 *
 * `Gallery::is_published` cannot be true without recorded consent — the model
 * refuses it. And every individual photograph still passes through
 * `x-media.image`, which refuses any file whose camera metadata has not been
 * stripped. A gallery from a school visit is a set of photographs of
 * identifiable children, and either gate alone would eventually let one
 * through.
 */
class GalleryController extends Controller
{
    private const PER_PAGE = 12;

    public function index(): View
    {
        return view('galleries.index', [
            'galleries' => Gallery::query()
                ->where('is_published', true)
                ->with('cover')
                ->withCount('items')
                ->orderByDesc('taken_on')
                ->paginate(self::PER_PAGE),
            'meta' => PageMeta::site(
                __('Gallery').setting('seo.title_suffix', ''),
                __('Photographs from our work.'),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Gallery'), 'url' => null],
            ],
        ]);
    }

    public function show(Gallery $gallery): View
    {
        if (! $gallery->is_published) {
            throw new NotFoundHttpException;
        }

        $gallery->load(['items.media', 'cover']);

        return view('galleries.show', [
            'gallery' => $gallery,
            'meta' => PageMeta::for($gallery, route('galleries.show', $gallery)),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Gallery'), 'url' => route('galleries.index')],
                ['label' => $gallery->title, 'url' => null],
            ],
        ]);
    }
}
