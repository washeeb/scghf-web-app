<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Beneficiary case records, and the documents attached to them.
 *
 * The most sensitive data this application holds: names, ages, addresses,
 * medical reports and case notes about vulnerable children, widows and the
 * elderly.
 *
 * ── Deliberately not reachable by a broad grant ─────────────────────────────
 *
 * `beneficiaries.view` sits in the `programmes` group, apart from the content
 * permissions, so that a Content Editor who needs `content.*` to do their job
 * does not acquire case files along the way. This policy adds nothing clever to
 * that — the separation is the control, and it is in the permission map.
 *
 * ── Hard deletion is refused ────────────────────────────────────────────────
 *
 * Not because the data must be kept — the opposite: Act 843 requires it to be
 * destroyed once its purpose ends. But that destruction is the retention
 * runner's job, and it does things a delete button cannot: it checks for a
 * legal hold, it writes an audit entry BEFORE destroying anything, it projects
 * the anonymous statistical record first, and it records a one-way digest so a
 * specific person can still be matched against the log.
 *
 * A member of staff clicking "delete" would skip all of that. So the button
 * does not exist, and erasure runs through
 * `App\Support\RetentionRunner` or a documented erasure request.
 */
class BeneficiaryPolicy extends BasePolicy
{
    protected function prefix(): string
    {
        return 'beneficiaries';
    }

    /**
     * Soft deletion — closing a record away from the working list.
     *
     * Allowed, because it destroys nothing: `close()` starts the retention
     * clock and the record stays lawfully identifiable for its whole period.
     */
    public function delete(User $user, Model $model): bool
    {
        return $this->permits($user, 'manage');
    }

    /**
     * Never. See the note on this class: destruction goes through the retention
     * runner, which does five things a delete button does not.
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    /**
     * Downloading a scanned Ghana Card, a medical report or a signed consent
     * form.
     *
     * Its own ability because the act is different in kind from reading a case
     * summary on screen: a downloaded file leaves the application's protections
     * behind and lands in somebody's Downloads folder, on a laptop, on a bus.
     * The audit trail records it as `beneficiary.document_downloaded` at
     * warning severity for the same reason.
     */
    public function download(User $user, Model $model): bool
    {
        return $this->permits($user, 'manage');
    }

    /**
     * Exporting more than one record at a time.
     *
     * Held to the same permission as managing them, and audited as
     * `beneficiaries.exported` at CRITICAL severity — the only export in the
     * system classified that way, because a spreadsheet of children's names and
     * addresses is the single worst thing that could leave this building.
     */
    public function export(User $user): bool
    {
        return $this->permits($user, 'manage');
    }
}
