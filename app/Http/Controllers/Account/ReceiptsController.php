<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The receipts archive, by tax year.
 *
 * ── Why by year ─────────────────────────────────────────────────────────────
 *
 * A donor claiming relief under Section 97 of the Income Tax Act files by
 * year of assessment, which for an individual is the calendar year — the
 * same year the receipt number carries (`SCGHF-R-2026-000041`). So the
 * archive is one section per year with the year's total, the deductible
 * total where the appeal qualified, and every receipt as a PDF.
 *
 * The download is the existing `receipts.download` route, which lets the
 * receipt's owner through without a signature. Ownership there is the
 * donor record the account has claimed, not only the `user_id` stamped on
 * a gift made while signed in — otherwise a receipt for a gift made from a
 * phone before the account existed would be in the list and refuse to open.
 *
 * Gifts that completed but have no receipt yet are counted, not hidden:
 * "2 gifts are still being receipted" is an honest line, and a receipt
 * that has not been issued is not something this page can hand over.
 */
class ReceiptsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $donor = $user?->donor;

        if ($donor === null) {
            return view('account.receipts', ['user' => $user, 'donor' => null, 'years' => collect(), 'unreceipted' => 0]);
        }

        $receipts = DonationReceipt::query()
            ->whereHas('donation', fn ($q) => $q->where('donor_id', $donor->getKey()))
            ->with('donation:id,ulid,cause_id,paid_at', 'donation.cause:id,title')
            ->orderByDesc('financial_year')
            ->orderByDesc('donated_on')
            ->orderByDesc('sequence')
            ->get();

        $unreceipted = Donation::query()
            ->where('donor_id', $donor->getKey())
            ->completed()
            ->whereDoesntHave('receipt')
            ->count();

        return view('account.receipts', [
            'user' => $user,
            'donor' => $donor,
            'years' => $this->byYear($receipts),
            'unreceipted' => $unreceipted,
        ]);
    }

    /**
     * @param  Collection<int, DonationReceipt>  $receipts
     * @return Collection<int, array{year: int, receipts: Collection<int, DonationReceipt>, total: Money, deductible: Money}>
     */
    private function byYear(Collection $receipts): Collection
    {
        return $receipts
            ->groupBy('financial_year')
            ->map(fn (Collection $group, int $year): array => [
                'year' => $year,
                'receipts' => $group->values(),
                'total' => $group->reduce(fn (Money $carry, DonationReceipt $r): Money => $carry->plus($r->amountReceived()), Money::zero()),
                'deductible' => $group->reduce(fn (Money $carry, DonationReceipt $r): Money => $carry->plus($r->deductible_amount ?? Money::zero()), Money::zero()),
            ])
            ->values();
    }
}
