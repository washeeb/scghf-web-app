<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TaxApproval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The single gate on tax-deductibility messaging.
 *
 * Every place that would tell a donor a gift is deductible — the donation form,
 * a cause page, a receipt, an email — asks this class first. Nothing else
 * decides.
 *
 * The rule it enforces: being incorporated or registered as a foundation does
 * NOT make donations deductible. Deductibility requires a current written GRA
 * approval under Act 896 s.97 (the organisation) or s.100 (a particular
 * worthwhile cause). Telling a donor otherwise sets them up for a claim the GRA
 * will refuse, which costs them money and costs the Foundation their trust.
 *
 * When no valid approval is on file, the answer is simply no — regardless of
 * what any cause record or CMS setting says.
 */
class TaxDeductibility
{
    private const CACHE_KEY = 'scghf.tax.approval.current';

    /**
     * Whether deductibility wording may be shown at all right now.
     *
     * The site-wide gate. A false here suppresses every deductibility claim on
     * the site, whatever individual causes are flagged as.
     */
    public function isEnabled(?\DateTimeInterface $on = null): bool
    {
        if (! config('compliance.tax.require_gra_approval', true)) {
            // Only for a jurisdiction that does not require approval. Left
            // configurable rather than hardcoded, but the default is to require.
            return true;
        }

        return $this->approvalOn($on) !== null;
    }

    /**
     * The approval covering the organisation as a whole (s.97).
     *
     * Cached briefly rather than for ever: an approval can be revoked at any
     * moment, and continuing to advertise deductibility for an hour after a
     * revocation is not acceptable. A short TTL keeps the hot path cheap
     * without letting the site lie for long.
     */
    public function currentApproval(): ?TaxApproval
    {
        $id = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(5),
            fn (): ?int => TaxApproval::query()
                ->current()
                ->where('approval_type', TaxApproval::TYPE_SECTION_97)
                ->orderByDesc('issued_on')
                ->value('id'),
        );

        if ($id === null) {
            return null;
        }

        $approval = TaxApproval::find($id);

        // Re-check against the dates rather than trusting the cached id. This
        // is the belt to the TTL's braces: an approval revoked or expired since
        // the cache was written must not authorise anything.
        return $approval?->isCurrentlyValid() ? $approval : null;
    }

    /**
     * The s.97 approval that was valid on a given date.
     *
     * Passing no date means "right now", which is the live-messaging case and
     * takes the cached path. Passing a date is the DOCUMENT case: an
     * acknowledgement for a donation made two years ago must cite the approval
     * that was valid then, because that is what was true when the gift was
     * received. Reprinting a receipt after an approval has since lapsed must not
     * silently rewrite history — and equally, a donation received while the
     * Foundation had no approval never acquires one retroactively.
     */
    public function approvalOn(?\DateTimeInterface $on = null): ?TaxApproval
    {
        if ($on === null) {
            return $this->currentApproval();
        }

        $approval = TaxApproval::query()
            ->validOn($on)
            ->where('approval_type', TaxApproval::TYPE_SECTION_97)
            ->orderByDesc('issued_on')
            ->first();

        return $approval?->isCurrentlyValid($on) ? $approval : null;
    }

    /**
     * Whether a specific donation destination qualifies.
     *
     * Two conditions, BOTH required:
     *   1. a current approval exists, and
     *   2. this particular destination is marked as a qualifying worthwhile
     *      cause, or is covered by a s.100 approval of its own.
     *
     * The second is not implied by the first. A s.97 approval makes the
     * organisation approved; it does not make every activity it runs a
     * worthwhile cause.
     */
    public function qualifies(?object $cause = null, ?\DateTimeInterface $on = null): bool
    {
        if (! $this->isEnabled($on)) {
            return false;
        }

        if ($cause === null) {
            return false;
        }

        // A cause with its own s.100 approval qualifies on that basis.
        if (isset($cause->tax_approval_id) && $cause->tax_approval_id !== null) {
            $specific = TaxApproval::find($cause->tax_approval_id);

            if ($specific?->isCurrentlyValid($on)) {
                return true;
            }
        }

        return (bool) ($cause->is_tax_deductible ?? false);
    }

    /**
     * The wording for a receipt, or null when nothing may be claimed.
     *
     * @return array{citation: string, disclaimer: string}|null
     */
    public function receiptStatement(?object $cause = null, ?\DateTimeInterface $on = null): ?array
    {
        if (! $this->qualifies($cause, $on)) {
            return null;
        }

        $approval = $this->approvalOn($on);

        if ($approval === null) {
            return null;
        }

        return [
            'citation' => $approval->citation(),
            // Never omitted. The receipt may confirm the gift and cite the
            // approval; it may not promise the donor a deduction, because that
            // is the GRA's determination on the donor's own return.
            'disclaimer' => config('compliance.tax.deduction_disclaimer'),
        ];
    }

    /**
     * Whether this payable may ever receive a charitable acknowledgement.
     *
     * A shop purchase is consideration for goods, not a gift. Issuing a
     * donation receipt for one would misrepresent the transaction to the
     * customer and to the GRA — so the prohibition is enforced here rather than
     * relying on nobody calling the wrong method.
     */
    public function mayAcknowledge(object $payable): bool
    {
        foreach (config('compliance.tax.never_acknowledge_payable_types', []) as $forbidden) {
            /*
             * A configured class that does not exist is a CONFIGURATION ERROR,
             * not an absence. `instanceof` against a missing class silently
             * returns false, which would turn a legally significant guard into
             * a no-op the day someone renames the model — and nothing would
             * fail, the site would just start issuing donation receipts for
             * shop purchases. So it is made loud instead.
             *
             * Classes for modules not yet built are expected and skipped
             * quietly; anything else is reported.
             */
            if (! class_exists($forbidden)) {
                if (! app()->runningUnitTests() && ! $this->isPendingModule($forbidden)) {
                    Log::warning('Configured payable type does not exist; acknowledgement guard inactive for it.', [
                        'class' => $forbidden,
                    ]);
                }

                continue;
            }

            if ($payable instanceof $forbidden) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a configured class belongs to a module that has not landed yet.
     *
     * EMPTY, as of Module 5. `App\Models\Order` used to be listed here — it was
     * referenced in the compliance config before the shop existed, so that the
     * rule was in place the moment orders became possible rather than being
     * remembered afterwards. The class exists now, so keeping it here would
     * silence the very warning that should fire if somebody renames it.
     *
     * The list stays because the same trick will be wanted again.
     */
    private function isPendingModule(string $class): bool
    {
        return in_array($class, [
            // Nothing pending.
        ], true);
    }

    /** Approvals nearing expiry, for the administrator warning. */
    public function expiringSoon(): Collection
    {
        $windows = config('compliance.tax.expiry_warning_days', [30]);

        return TaxApproval::query()
            ->expiringWithin(max($windows))
            ->orderBy('expires_on')
            ->get();
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
