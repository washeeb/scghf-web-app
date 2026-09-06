<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cause;
use App\Support\PageMeta;
use Illuminate\View\View;

/**
 * How to give without a card.
 *
 * ── Seven settings, read by nothing, for two phases ─────────────────────────
 *
 * `banking.bank_name`, `bank_branch`, `account_name`, `account_number`, `swift`,
 * `momo_name` and `momo_number` have been seeded since Phase 3 and appeared on
 * no page. For a Ghanaian foundation that is not a minor omission: mobile money
 * is how a very large share of giving actually happens, and a supporter who
 * cannot find the merchant number does not reach for a card — they give
 * nothing, and nobody ever learns that they tried.
 *
 * ── This page exists whether or not online giving does ──────────────────────
 *
 * The card form arrives with the donation module. Bank transfer and Mobile
 * Money work today, need no gateway, and cost the foundation less per gift —
 * which is why this is a page in its own right rather than a footnote under a
 * payment button that has not been built.
 *
 * ── Nothing is invented ─────────────────────────────────────────────────────
 *
 * Every field comes from the settings layer and an unfilled one is omitted
 * rather than rendered as an empty label. A bank details block showing "Account
 * number:" with nothing after it is worse than no block: it reads as a page
 * that is broken, on the one page where a visitor most needs to trust what they
 * are looking at.
 */
class GivingController extends Controller
{
    public function __invoke(): View
    {
        return view('give', [
            'bank' => $this->bankDetails(),
            'momo' => $this->mobileMoney(),

            /*
             * The open appeals, so somebody who came here to give has something
             * to give TO. A transfer with no reference against no appeal is a
             * gift the finance volunteer cannot allocate.
             */
            'causes' => Cause::query()
                ->live()
                ->orderByDesc('is_featured')
                ->orderBy('sort_order')
                ->limit(6)
                ->get()
                ->filter(fn (Cause $cause): bool => $cause->acceptsDonations())
                ->values(),

            'meta' => PageMeta::site(
                __('Ways to give').setting('seo.title_suffix', ''),
                __('Bank transfer and Mobile Money details for :name.', [
                    'name' => setting('general.short_name', config('app.name')),
                ]),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Ways to give'), 'url' => null],
            ],
        ]);
    }

    /**
     * The bank account, or nothing.
     *
     * Returns an empty array unless the two fields that make a transfer
     * possible are both present. Half a set of bank details is a transfer that
     * bounces and a donor who has to ring the office.
     *
     * @return array<string, string>
     */
    private function bankDetails(): array
    {
        $account = setting('banking.account_number');
        $name = setting('banking.account_name');

        if (blank($account) || blank($name)) {
            return [];
        }

        return array_filter([
            __('Account name') => $name,
            __('Account number') => $account,
            __('Bank') => setting('banking.bank_name'),
            __('Branch') => setting('banking.bank_branch'),
            // Only needed from abroad, and meaningless without it.
            __('SWIFT / BIC') => setting('banking.swift'),
        ]);
    }

    /**
     * The Mobile Money merchant, or nothing.
     *
     * @return array<string, string>
     */
    private function mobileMoney(): array
    {
        $number = setting('banking.momo_number');

        if (blank($number)) {
            return [];
        }

        return array_filter([
            __('Merchant number') => $number,
            __('Registered name') => setting('banking.momo_name'),
        ]);
    }
}
