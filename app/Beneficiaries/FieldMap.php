<?php

declare(strict_types=1);

namespace App\Beneficiaries;

/**
 * Every field on a case, and who sees it — §4 of the design note, as data.
 *
 * The form, the case page, the list and the export are all generated from
 * this map, and the access-matrix test walks the same map. That is the
 * point: a field that is here is classified, and the classification cannot
 * drift from the screens because the screens are made from it.
 *
 * ── Views ───────────────────────────────────────────────────────────────────
 *
 * A field is included in one or more VIEWS. An actor holds a set of views
 * (see CaseAccess::views()) and sees the union.
 *
 *   A  case summary — anybody with `beneficiaries.view`
 *   B  case working — the worker on the case, the Safeguarding Lead, Super Admin
 *   C  special category and financial — the same three; Super Admin read-only
 *   F  what Finance needs to pay an approved case (`view_financial`)
 *   U  what the Auditor needs to audit the process (`audit`)
 *
 * `edit` lists the actor kinds that may change the field: worker, lead,
 * super, finance. Absent means read-only for everyone (the reference, the
 * lifecycle dates). `age_band` is how `date_of_birth` appears to anybody
 * who is not on view B.
 */
final class FieldMap
{
    public const VIEW_SUMMARY = 'A';

    public const VIEW_WORKING = 'B';

    public const VIEW_SENSITIVE = 'C';

    public const VIEW_FINANCIAL = 'F';

    public const VIEW_AUDIT = 'U';

    /** Sections the case page is laid out in, in order. */
    public const SECTIONS = ['summary', 'person', 'sensitive', 'money'];

    /**
     * @var array<string, array{views: array<int, string>, edit: array<int, string>, section: string, label: string, type: string}>
     */
    public const FIELDS = [
        // ── Tier A: the case exists, where it is, who has it ─────────────
        'case_reference' => ['views' => ['A'], 'edit' => [], 'section' => 'summary', 'label' => 'Case reference', 'type' => 'text'],
        'status' => ['views' => ['A'], 'edit' => [], 'section' => 'summary', 'label' => 'Status', 'type' => 'status'],
        'division_id' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Division', 'type' => 'division'],
        'project_id' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Project', 'type' => 'project'],
        'focus_area_id' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Area of work', 'type' => 'focus_area'],
        'case_worker_id' => ['views' => ['A'], 'edit' => ['lead', 'super'], 'section' => 'summary', 'label' => 'Case worker', 'type' => 'user'],
        'full_name' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Full name', 'type' => 'text'],
        'other_names' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Other names', 'type' => 'text'],
        'gender' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Gender', 'type' => 'gender'],
        'region' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Region', 'type' => 'text'],
        'district' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'District', 'type' => 'text'],
        'community' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Community', 'type' => 'text'],
        'photo_id' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Photograph', 'type' => 'media'],
        'submitted_at' => ['views' => ['A'], 'edit' => [], 'section' => 'summary', 'label' => 'Submitted', 'type' => 'datetime'],
        'decided_at' => ['views' => ['A'], 'edit' => [], 'section' => 'summary', 'label' => 'Decided', 'type' => 'datetime'],
        'closed_at' => ['views' => ['A'], 'edit' => [], 'section' => 'summary', 'label' => 'Closed', 'type' => 'datetime'],
        'last_activity_at' => ['views' => ['A'], 'edit' => [], 'section' => 'summary', 'label' => 'Last activity', 'type' => 'datetime'],

        // ── Tier B: what a case worker needs ─────────────────────────────
        'phone' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Phone', 'type' => 'text'],
        'email' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Email', 'type' => 'email'],
        'address' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Address', 'type' => 'text'],
        'date_of_birth' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Date of birth', 'type' => 'date'],
        'age_band' => ['views' => ['A', 'U', 'F'], 'edit' => [], 'section' => 'summary', 'label' => 'Age', 'type' => 'age_band'],
        'next_of_kin_name' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Next of kin', 'type' => 'text'],
        'next_of_kin_phone' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Next of kin phone', 'type' => 'text'],
        'household_details' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Household', 'type' => 'textarea'],
        'school_or_employer' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'School or employer', 'type' => 'text'],
        'application_narrative' => ['views' => ['B', 'U'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Application', 'type' => 'textarea'],
        'latitude' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Latitude', 'type' => 'decimal'],
        'longitude' => ['views' => ['B'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'person', 'label' => 'Longitude', 'type' => 'decimal'],
        'assistance' => ['views' => ['B', 'F', 'U'], 'edit' => ['worker', 'lead', 'super', 'finance'], 'section' => 'money', 'label' => 'Assistance', 'type' => 'money'],
        'assisted_on' => ['views' => ['B', 'F', 'U'], 'edit' => ['worker', 'lead', 'super', 'finance'], 'section' => 'money', 'label' => 'Assisted on', 'type' => 'date'],
        'outcome' => ['views' => ['A'], 'edit' => ['worker', 'lead', 'super'], 'section' => 'summary', 'label' => 'Outcome', 'type' => 'outcome'],

        // ── Tier C: special category and financial detail ─────────────────
        'religion' => ['views' => ['C'], 'edit' => ['worker', 'lead'], 'section' => 'sensitive', 'label' => 'Religion', 'type' => 'text'],
        'medical_notes' => ['views' => ['C'], 'edit' => ['worker', 'lead'], 'section' => 'sensitive', 'label' => 'Medical notes', 'type' => 'textarea'],
        'ghana_card_number' => ['views' => ['C'], 'edit' => ['worker', 'lead'], 'section' => 'sensitive', 'label' => 'Ghana Card number', 'type' => 'masked'],
        'bank_account' => ['views' => ['C', 'F'], 'edit' => ['worker', 'lead'], 'section' => 'money', 'label' => 'Bank account', 'type' => 'text'],
        'momo_number' => ['views' => ['C', 'F'], 'edit' => ['worker', 'lead'], 'section' => 'money', 'label' => 'Mobile Money number', 'type' => 'text'],
        'id_document_id' => ['views' => ['C'], 'edit' => ['worker', 'lead'], 'section' => 'sensitive', 'label' => 'Identity document', 'type' => 'media'],
        'signature_id' => ['views' => ['C'], 'edit' => ['worker', 'lead'], 'section' => 'sensitive', 'label' => 'Signature', 'type' => 'media'],
        'intake_ip' => ['views' => ['C', 'U'], 'edit' => [], 'section' => 'sensitive', 'label' => 'Recorded from', 'type' => 'text'],
    ];

    /** Columns on the table that are not shown as fields at all. */
    public const NOT_A_FIELD = [
        'id', 'ulid', 'created_at', 'updated_at', 'deleted_at', 'currency', 'created_by',
        'assistance_minor',   // shown as `assistance`
        'ghana_card_index',   // the blind index; never shown
        'case_notes',         // the legacy column; notes are rows now
    ];

    /**
     * The fields an actor holding these views may see, in map order.
     *
     * @param  array<int, string>  $views
     * @return array<int, string>
     */
    public static function visible(array $views): array
    {
        $out = [];

        foreach (self::FIELDS as $field => $spec) {
            if (array_intersect($spec['views'], $views) !== []) {
                $out[] = $field;
            }
        }

        // The birth date and the age band are one fact at two precisions;
        // whoever sees the date does not also need the band.
        if (in_array('date_of_birth', $out, true)) {
            $out = array_values(array_diff($out, ['age_band']));
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $views
     * @return array<int, string>
     */
    public static function editable(array $views, string $actor): array
    {
        return array_values(array_filter(
            self::visible($views),
            fn (string $field): bool => in_array($actor, self::FIELDS[$field]['edit'], true),
        ));
    }

    /** @return array<int, string> */
    public static function inSection(string $section): array
    {
        return array_keys(array_filter(self::FIELDS, fn (array $spec): bool => $spec['section'] === $section));
    }
}
