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
     * The event the retention period is measured FROM.
     *
     * Not the event that triggers destruction. Closing a case starts the clock;
     * the record then stays lawfully identifiable for the whole retention
     * period and is only acted on once `anchor + months + grace` has passed.
     * Confusing the two would destroy live case data the day a case closes.
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
     * Every column on this model that holds data about a person, mapped to its
     * privacy element in config('compliance.privacy.elements').
     *
     *     ['full_name' => 'name', 'ghana_card_number' => 'national_id', ...]
     *
     * Static because it describes the table, not a row, and because a test
     * needs to compare it against the schema without instantiating anything.
     *
     * The map is what makes the de-identification boundary enforceable. A test
     * asserts that every column on the table is either mapped here or listed as
     * exempt — so the failure mode this guards against is not a wrong decision,
     * it is a column added in two years' time that nobody classified at all and
     * that therefore survives de-identification untouched.
     *
     * @return array<string, string>
     */
    public static function privacyElements(): array;

    /**
     * Columns that hold no personal data and need no classification.
     *
     * Keys, timestamps, structural foreign keys. Listing them explicitly rather
     * than inferring them keeps the decision visible in the model.
     *
     * @return array<int, string>
     */
    public static function privacyExempt(): array;

    /**
     * Destroy the personal identifiers on this record.
     *
     * Every column mapped to a `destroy` element is overwritten and cleared.
     * Columns mapped to `generalise` or `keep` are NOT coarsened in place —
     * a band cannot be written into an integer amount column — they belong to
     * the anonymous analytics projection, which is a separate dataset with no
     * reversible link back here.
     *
     * Must be idempotent, so a partial failure can be re-run.
     */
    public function deIdentify(): void;

    /**
     * A one-way digest of the identifying values, taken BEFORE destruction.
     *
     * Written to the retention log so a specific person can be matched against
     * it on request, without the log itself holding their details. Returning
     * null is acceptable where no such match is needed.
     *
     * Note this digest is a pseudonym, not an anonymisation: it is in the log
     * precisely so it can be matched. That is lawful because the log carries no
     * other personal data and exists to evidence compliance — but it is why the
     * log has its own retention period rather than living for ever.
     */
    public function retentionDigest(): ?string;
}
