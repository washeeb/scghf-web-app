<?php

declare(strict_types=1);

namespace App\Beneficiaries;

use App\Models\Beneficiary;
use App\Models\BeneficiaryDocument;
use App\Models\User;

/**
 * Who a person is to a case, and therefore what they see and may change.
 *
 * ── Why this is not only the policy ─────────────────────────────────────────
 *
 * The policy answers "may this user open this case at all". Inside the
 * case, what shows depends on the RELATIONSHIP — the worker on it, the
 * safeguarding lead over all of them, Finance paying it, the Auditor
 * reading the process — and on the field. `Gate::before` gives the Super
 * Admin every ability, which is right for fixing the system and wrong for
 * a child's medical note; so the actor is worked out from the permissions
 * a role actually holds (`getAllPermissions`), not from `can()`, and the
 * wildcard buys the Super Admin views A, B and C read-only, exactly as the
 * design note says.
 *
 * The permission names are seeded in RoleAndPermissionSeeder; the views
 * are in FieldMap; the matrix test walks both.
 */
final class CaseAccess
{
    public const WORKER = 'worker';

    public const LEAD = 'lead';

    public const SUPER = 'super';

    public const FINANCE = 'finance';

    public const AUDITOR = 'auditor';

    public const VIEWER = 'viewer';

    public const NONE = 'none';

    /** The actor's relationship to the case (or to cases in general when null). */
    public function actor(User $user, ?Beneficiary $case = null): string
    {
        if (! $user->can('beneficiaries.view')) {
            return self::NONE;
        }

        if ($case !== null && $case->case_worker_id !== null && (int) $case->case_worker_id === (int) $user->getKey()) {
            return self::WORKER;
        }

        if ($this->holds($user, 'beneficiaries.view_sensitive')) {
            return self::LEAD;
        }

        if ($user->hasRole('Super Admin')) {
            return self::SUPER;
        }

        if ($this->holds($user, 'beneficiaries.view_financial')) {
            return self::FINANCE;
        }

        if ($this->holds($user, 'beneficiaries.audit')) {
            return self::AUDITOR;
        }

        return self::VIEWER;
    }

    /**
     * The views this actor holds on this case.
     *
     * @return array<int, string>
     */
    public function views(User $user, ?Beneficiary $case = null): array
    {
        return match ($this->actor($user, $case)) {
            self::WORKER, self::LEAD, self::SUPER => [FieldMap::VIEW_SUMMARY, FieldMap::VIEW_WORKING, FieldMap::VIEW_SENSITIVE],
            self::FINANCE => $case === null || $this->payable($case)
                ? [FieldMap::VIEW_SUMMARY, FieldMap::VIEW_FINANCIAL]
                : [],
            self::AUDITOR => [FieldMap::VIEW_SUMMARY, FieldMap::VIEW_AUDIT],
            self::VIEWER => [FieldMap::VIEW_SUMMARY],
            default => [],
        };
    }

    /** May open the case page at all. Finance only once there is something to pay. */
    public function canView(User $user, Beneficiary $case): bool
    {
        return $this->views($user, $case) !== [];
    }

    /** @return array<int, string> */
    public function visibleFields(User $user, ?Beneficiary $case = null): array
    {
        return FieldMap::visible($this->views($user, $case));
    }

    /** @return array<int, string> */
    public function editableFields(User $user, ?Beneficiary $case = null): array
    {
        if ($case !== null && $this->finished($case)) {
            return [];
        }

        return FieldMap::editable($this->views($user, $case), $this->actor($user, $case));
    }

    public function canEdit(User $user, Beneficiary $case): bool
    {
        return $this->editableFields($user, $case) !== [];
    }

    /** Tier C on screen: the worker, the lead, the Super Admin. */
    public function canSeeSensitive(User $user, Beneficiary $case): bool
    {
        return in_array(FieldMap::VIEW_SENSITIVE, $this->views($user, $case), true);
    }

    /** Creating a case: anybody who manages cases becomes its worker. */
    public function canCreate(User $user): bool
    {
        return $user->can('beneficiaries.manage');
    }

    /** Moving a case to another worker, or taking one: the lead and the Super Admin. */
    public function canReassign(User $user, Beneficiary $case): bool
    {
        return in_array($this->actor($user, $case), [self::LEAD, self::SUPER], true);
    }

    /** Approve or decline: a decision, which is the lead's (or the Super Admin's). */
    public function canDecide(User $user, Beneficiary $case): bool
    {
        return in_array($this->actor($user, $case), [self::LEAD, self::SUPER], true) && ! $this->finished($case);
    }

    /** Submit, withdraw, close: the worker's day-to-day, plus the lead and the Super Admin. */
    public function canProgress(User $user, Beneficiary $case): bool
    {
        return in_array($this->actor($user, $case), [self::WORKER, self::LEAD, self::SUPER], true) && ! $this->finished($case);
    }

    /** Add to the case log: anybody on views B or U — never a plain viewer. */
    public function canNote(User $user, Beneficiary $case): bool
    {
        $views = $this->views($user, $case);

        return in_array(FieldMap::VIEW_WORKING, $views, true) || in_array(FieldMap::VIEW_AUDIT, $views, true);
    }

    /** Consent is recorded by the people who work the case, and the lead. */
    public function canRecordConsent(User $user, Beneficiary $case): bool
    {
        return in_array($this->actor($user, $case), [self::WORKER, self::LEAD, self::SUPER], true) && $user->can('consents.manage');
    }

    /**
     * Opening a document. Sensitive ones (medical, identity) need view C;
     * financial ones open to Finance and the Auditor as well; the rest need B.
     */
    public function canDownload(User $user, BeneficiaryDocument $document): bool
    {
        $case = $document->beneficiary;

        if ($case === null) {
            return false;
        }

        $views = $this->views($user, $case);

        if ($document->is_sensitive) {
            return in_array(FieldMap::VIEW_SENSITIVE, $views, true);
        }

        if ($document->document_type === BeneficiaryDocument::TYPE_FINANCIAL) {
            return array_intersect([FieldMap::VIEW_WORKING, FieldMap::VIEW_FINANCIAL, FieldMap::VIEW_AUDIT], $views) !== [];
        }

        return array_intersect([FieldMap::VIEW_WORKING, FieldMap::VIEW_AUDIT], $views) !== [];
    }

    public function canUpload(User $user, Beneficiary $case): bool
    {
        return in_array($this->actor($user, $case), [self::WORKER, self::LEAD, self::SUPER], true) && ! $this->finished($case);
    }

    /** The one CSV of names: the permission held directly, never by wildcard. */
    public function canExport(User $user): bool
    {
        return $this->holds($user, 'beneficiaries.export');
    }

    public function finished(Beneficiary $case): bool
    {
        return in_array($case->status, [
            Beneficiary::STATUS_CLOSED, Beneficiary::STATUS_DECLINED, Beneficiary::STATUS_WITHDRAWN,
        ], true);
    }

    private function payable(Beneficiary $case): bool
    {
        return in_array($case->status, [Beneficiary::STATUS_APPROVED, Beneficiary::STATUS_CLOSED], true);
    }

    /**
     * A permission the user's roles actually carry — the wildcard does not
     * count. `getAllPermissions()` is what spatie resolves from the roles;
     * `can()` would also ask Gate::before, which says yes to a Super Admin.
     */
    private function holds(User $user, string $permission): bool
    {
        return $user->getAllPermissions()->contains('name', $permission);
    }
}
