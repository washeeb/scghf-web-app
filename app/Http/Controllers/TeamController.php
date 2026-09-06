<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TeamDepartment;
use App\Models\TeamMember;
use App\Support\PageMeta;
use Illuminate\View\View;

/**
 * Who runs the foundation.
 *
 * ── Only what the record says is public ─────────────────────────────────────
 *
 * `public_email`, and never the linked account's address. A trustee's private
 * email published on a page a scraper reads within the hour is a real risk to a
 * real person, and the usual way it happens is a template that helpfully
 * reaches through to the user record because the relationship was there.
 *
 * ── Somebody who has left stays on record and off the page ──────────────────
 *
 * `left_on` keeps the history without publishing it. "Who were the trustees in
 * 2026?" is a question a funder asks, and deleting people to take them off the
 * page is how it stops being answerable.
 */
class TeamController extends Controller
{
    public function __invoke(): View
    {
        $members = TeamMember::query()
            ->where('is_published', true)
            ->with(['photo', 'department'])
            ->orderBy('sort_order')
            ->get()
            // Current only. `isCurrent()` is on the model and reads `left_on`.
            ->filter(fn (TeamMember $member): bool => $member->isCurrent());

        $departments = TeamDepartment::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->get();

        return view('team', [
            'departments' => $departments,
            'members' => $members,
            'meta' => PageMeta::site(
                __('Our team').setting('seo.title_suffix', ''),
                __('The trustees, staff and volunteers behind :name.', [
                    'name' => setting('general.short_name', config('app.name')),
                ]),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Our team'), 'url' => null],
            ],
        ]);
    }
}
