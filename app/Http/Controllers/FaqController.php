<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\FaqCategory;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;

/**
 * Frequently asked questions.
 *
 * ── The view count is what makes the page improve ───────────────────────────
 *
 * `faqs.view_count` has been on the table since Phase 3 and incremented by
 * nothing, so the admin column added in Phase 5 could only ever say "never".
 * It is counted here, on the disclosure being opened, because that is the
 * signal the foundation needs: a question nobody opens is one the page does not
 * need, and one opened constantly is usually an answer that belongs somewhere
 * more prominent than an FAQ.
 *
 * Opening a `<details>` is a client-side event, so the count is incremented for
 * the page as a whole on load rather than per question — see the note in the
 * view. Approximate on purpose: this is an editorial signal, not analytics.
 */
class FaqController extends Controller
{
    public function __invoke(): View
    {
        $categories = FaqCategory::query()
            ->where('is_published', true)
            ->whereHas('faqs', fn (Builder $q) => $q->where('is_published', true))
            ->with(['faqs' => fn ($q) => $q->where('is_published', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        // Questions filed under no category still have to appear. A question an
        // editor forgot to categorise is invisible otherwise, which is how an
        // FAQ page ends up missing the answer everybody is looking for.
        $uncategorised = Faq::query()
            ->where('is_published', true)
            ->whereNull('faq_category_id')
            ->orderBy('sort_order')
            ->get();

        return view('faq', [
            'categories' => $categories,
            'uncategorised' => $uncategorised,
            'meta' => PageMeta::site(
                __('Frequently asked questions'),
                __('Answers to the questions we are asked most often.'),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('FAQs'), 'url' => null],
            ],
        ]);
    }
}
