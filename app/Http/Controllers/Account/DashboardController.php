<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What a donor sees when they sign in.
 *
 * ── Why this read is not audited ────────────────────────────────────────────
 *
 * README's rule is that reading personal data records an entry through
 * AuditLogger, because those reads change no model and nothing else would
 * notice them. This read is the exception, and the exception is worth stating
 * so the next person does not "fix" it.
 *
 * The question the audit trail answers is who looked at somebody ELSE's
 * records. Here the reader IS the data subject, and Act 843's accountability
 * duty is about the foundation's handling of data, not about a person reading
 * their own. Recording it would put a permanent, hash-chained, never-swept row
 * in `audit_logs` every time a donor refreshed their own dashboard — which
 * fills the table that most needs to stay readable with the least interesting
 * event in it.
 *
 * Exports are different, and will be audited when they exist: an export takes
 * data out of the application, where the trail ends.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $donor = $user?->donor;

        /*
         * Five rows, not the relationship.
         *
         * A donor with a decade of giving has a lot of donations and this page
         * shows the most recent handful. `latest()->limit()` is the query;
         * loading the relationship would be the whole history, on every page
         * load, over a mobile connection.
         */
        $recent = $donor === null
            ? collect()
            : Donation::where('donor_id', $donor->getKey())
                ->completed()
                ->with('cause:id,title,slug')
                ->latest('paid_at')
                ->limit(5)
                ->get();

        return view('account.dashboard', [
            'user' => $user,
            'donor' => $donor,
            'recent' => $recent,
        ]);
    }
}
