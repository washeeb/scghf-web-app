<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rendering a CMS page.
 *
 * ── A draft is a 404, not a 403 ─────────────────────────────────────────────
 *
 * An unpublished page does not exist as far as the public is concerned. A 403
 * would confirm that something is there, which is exactly what a draft is meant
 * not to do — and the address of an unannounced appeal is worth guessing.
 *
 * ── Preview is signed and staff-only, both ──────────────────────────────────
 *
 * The obvious implementation is a secret URL, and a secret URL is a URL: it ends
 * up in a chat message, a browser history, a referrer header. So the preview
 * route requires a signature AND an authenticated member of staff, which means
 * a leaked link is useless to anybody outside the foundation.
 */
class PageController extends Controller
{
    /**
     * The homepage.
     *
     * Whichever page is marked as the homepage, falling back to the static
     * welcome template when nobody has marked one — a fresh install must not
     * answer its own front page with a 404.
     */
    public function home(): View
    {
        $page = Page::query()
            ->where('is_homepage', true)
            ->with($this->eagerLoads())
            ->first();

        return $page !== null && $page->isLive()
            ? $this->render($page)
            : view('home');
    }

    /**
     * Any other page, by its full path.
     *
     * `path` is a materialised column, so `/about/leadership` is one lookup
     * rather than a walk down the tree — which is why `Page::getRouteKeyName()`
     * is `path` in the first place.
     */
    public function show(string $path): View
    {
        $page = Page::query()
            ->where('path', '/'.trim($path, '/'))
            ->with($this->eagerLoads())
            ->first();

        if ($page === null || ! $page->isLive()) {
            throw new NotFoundHttpException;
        }

        return $this->render($page);
    }

    /**
     * The same page, whatever its status, for somebody who may edit it.
     *
     * Three gates, and all three are needed. `signed` proves the link came from
     * the panel. `auth` and the policy check prove the person opening it is
     * entitled to — because a signed URL, once generated, is a string somebody
     * can paste anywhere.
     */
    public function preview(Request $request, Page $page): View
    {
        $user = $request->user();

        if ($user === null || $user->cannot('view', $page)) {
            throw new NotFoundHttpException;
        }

        $page->load($this->eagerLoads());

        return $this->render($page, isPreview: true);
    }

    private function render(Page $page, bool $isPreview = false): View
    {
        return view('page', [
            'page' => $page,
            /*
             * Filtered here rather than in the template.
             *
             * `isRenderable()` drops a hidden block, one outside its date
             * window, and one whose type has vanished from the registry —
             * removing a block from the code must not take down every page that
             * still has one placed.
             */
            'sections' => $page->sections
                ->filter(fn ($section) => $section->isRenderable())
                ->values(),
            'isPreview' => $isPreview,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function eagerLoads(): array
    {
        // `seo` for the head, `sections` for the body. Without these a page with
        // twelve blocks is thirteen queries before a single block has asked for
        // anything of its own.
        return ['seo', 'sections'];
    }
}
