<?php

declare(strict_types=1);

namespace App\Policies;

use App\Beneficiaries\CaseAccess;
use App\Models\Beneficiary;
use App\Models\BeneficiaryDocument;
use App\Models\BeneficiaryNote;
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
 * ── Inside a case, the relationship decides (Wave 2) ────────────────────────
 *
 * `beneficiaries.view` opens the LIST. Opening a CASE, and what shows on
 * it, depends on who the person is to it: the worker on it, the
 * Safeguarding Lead, Finance paying it, the Auditor reading it. That is
 * `App\Beneficiaries\CaseAccess`, and the field-level rules are in
 * `App\Beneficiaries\FieldMap`. This policy delegates to it so that
 * Filament's `can('view')`, `can('update')` and the screens agree.
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
 *
 * (A Super Admin passes every gate through Gate::before, including this
 * one's `false`. The resource therefore registers no delete action at all,
 * which is the control that actually holds.)
 */
class BeneficiaryPolicy extends BasePolicy
{
    public function __construct(private readonly CaseAccess $access) {}

    protected function prefix(): string
    {
        return 'beneficiaries';
    }

    public function view(User $user, Model $model): bool
    {
        $case = $this->caseBehind($model);

        return $case instanceof Beneficiary && ($model instanceof BeneficiaryNote
            ? $this->access->canNote($user, $case)
            : $this->access->canView($user, $case));
    }

    public function create(User $user): bool
    {
        return $this->access->canCreate($user);
    }

    public function update(User $user, Model $model): bool
    {
        if ($model instanceof BeneficiaryNote) {
            return false; // append-only; the model throws too
        }

        $case = $this->caseBehind($model);

        return $case instanceof Beneficiary && $this->access->canEdit($user, $case);
    }

    private function caseBehind(Model $model): ?Beneficiary
    {
        return match (true) {
            $model instanceof Beneficiary => $model,
            $model instanceof BeneficiaryDocument, $model instanceof BeneficiaryNote => $model->beneficiary,
            default => null,
        };
    }

    /**
     * Soft deletion — closing a record away from the working list.
     *
     * Allowed, because it destroys nothing: `close()` starts the retention
     * clock and the record stays lawfully identifiable for its whole period.
     */
    public function delete(User $user, Model $model): bool
    {
        return $model instanceof Beneficiary && $this->access->canProgress($user, $model);
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
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
     *
     * Asked of a document: the document's own rule (sensitive needs view C).
     * Asked of a case: whether the person could download anything on it.
     */
    public function download(User $user, Model $model): bool
    {
        if ($model instanceof BeneficiaryDocument) {
            return $this->access->canDownload($user, $model);
        }

        return $model instanceof Beneficiary && $this->access->canUpload($user, $model);
    }

    /**
     * Exporting more than one record at a time.
     *
     * Held by the data-protection lead alone (`beneficiaries.export`, never
     * by wildcard) and audited as `beneficiaries.exported` at CRITICAL
     * severity — the only export in the system classified that way, because
     * a spreadsheet of children's names and addresses is the single worst
     * thing that could leave this building.
     */
    public function export(User $user): bool
    {
        return $this->access->canExport($user);
    }
}
