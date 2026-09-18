<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Donors\ImpactTimeline;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Your impact" — the donor's gifts and what followed them, as one story.
 *
 * Read by the data subject about their own giving, so not audited, for the
 * reason `DashboardController` gives. The figures on it are the public
 * ones, through the same disclosure control the impact page uses.
 */
class ImpactController extends Controller
{
    public function __invoke(Request $request, ImpactTimeline $timeline): View
    {
        $donor = $request->user()?->donor;

        return view('account.impact', [
            'user' => $request->user(),
            'donor' => $donor,
            'entries' => $donor === null ? collect() : $timeline->for($donor),
        ]);
    }
}
