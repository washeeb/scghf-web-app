<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Support\Carbon;

/**
 * A record subject to a retention schedule.
 *
 * Implemented by anything holding personal data with a purpose-limited life —
 * beneficiary applications, case records, sensitive supporting documents.
 *
 * The contract is deliberately small. Deciding WHEN a record is due is the
 * framework's job, driven by config; knowing what to destroy and what to keep
 * is the model's, because only the model knows which of its columns identify a
 * person and which are the statistical shell worth keeping.
 */
interface Retainable
{
    /**
     * Which policy in config('compliance.retention.classes') applies.
     *
     * Returned per instance rather than per class because the same model can
     * fall under different rules depending on its state: a declined application
     * and an approved case record are the same table with very different lives.
     */
    public function retentionClass(): string;

    /**
     * The event the retention period is measured from.
     *
     * Null means the clock has not started — an open case has no closure date,
     * and must never be swept up by a retention run.
     */
    public function retentionAnchorDate(): ?Carbon;

    /**
     * An optional grouping a legal hold can target.
     *
     * Lets a hold cover "every beneficiary record for the 2025 education
     * programme" without enumerating them, which is how such instructions
     * actually arrive.
     */
    public function retentionScopeKey(): ?string;

    /**
     * Destroy the personal identifiers, keep the statistical shell.
     *
     * Used where the financial or programme trail must survive but the person
     * must not remain identifiable — an approved case record after six years.
     * Must be irreversible: overwrite the columns, do not merely null them, and
     * detach any media.
     *
     * Implementations should be idempotent, so a partial failure can be re-run.
     */
    public function deIdentify(): void;

    /**
     * A one-way digest of the identifying values, taken BEFORE destruction.
     *
     * Written to the retention log so a specific person can be matched against
     * it on request, without the log itself holding their details. Returning
     * null is acceptable where no such match is needed.
     */
    public function retentionDigest(): ?string;
}
