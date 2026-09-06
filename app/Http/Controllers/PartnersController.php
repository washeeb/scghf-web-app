<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Support\PageMeta;
use Illuminate\View\View;

/**
 * Who we work with.
 *
 * Current partners lead; past ones are shown separately rather than removed.
 * "Who have you worked with?" is a question a funder asks, and a partner deleted
 * because the work finished is a piece of the foundation's history gone.
 */
class PartnersController extends Controller
{
    public function __invoke(): View
    {
        $partners = Partner::query()
            ->where('is_published', true)
            ->with('logo')
            ->orderBy('sort_order')
            ->get();

        return view('partners', [
            'current' => $partners->filter(fn (Partner $partner): bool => $partner->partnership_ended_on === null
                || $partner->partnership_ended_on->isFuture()),

            'past' => $partners->filter(fn (Partner $partner): bool => $partner->partnership_ended_on !== null
                && $partner->partnership_ended_on->isPast()),

            'meta' => PageMeta::site(
                __('Our partners').setting('seo.title_suffix', ''),
                __('The organisations we work with.'),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Our partners'), 'url' => null],
            ],
        ]);
    }
}
