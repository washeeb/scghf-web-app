<?php

declare(strict_types=1);
use App\Models\Order;

/*
|--------------------------------------------------------------------------
| Compliance policy
|--------------------------------------------------------------------------
|
| Retention periods, tax-deductibility rules and the approved shop taxonomy.
|
| These live in CONFIG, not in an admin-editable table, on purpose. They are
| legal policy: they change rarely, every change should be reviewed like code,
| and the change history should be in git where it can be produced for an
| auditor. A retention schedule an administrator can quietly shorten is not a
| retention schedule.
|
| What IS in the database: legal holds, the deletion audit log, and the GRA
| approval record — because those are operational facts, not policy.
|
| References:
|   Act 843  — Ghana Data Protection Act, 2012, s.24 (retention no longer than
|              necessary for the purpose)
|   Act 896  — Income Tax Act, 2015, s.97 (approved charitable organisation)
|              and s.100 (worthwhile cause)
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Beneficiary data retention — purpose-based, per Act 843
    |--------------------------------------------------------------------------
    |
    | Each class states WHY the data is kept and for how long after its anchor
    | event. "Purpose-based" is the operative phrase: a period is defensible
    | only if tied to a purpose, which is why every entry carries one.
    |
    | `months` is measured from the anchor date. `action` is what happens when
    | it elapses:
    |
    |   delete        secure hard delete of the record and its files
    |   de_identify   personal identifiers destroyed, the statistical shell kept
    |
    */
    'retention' => [

        'classes' => [

            'beneficiary_application_declined' => [
                'label' => 'Declined beneficiary application',
                'months' => 24,
                'anchor' => 'decided_at',
                'action' => 'delete',
                'purpose' => 'Defence of a complaint or appeal about the decision, and '
                    .'detection of repeat or duplicate applications.',
            ],

            'beneficiary_application_withdrawn' => [
                'label' => 'Incomplete or withdrawn application',
                'months' => 12,
                'anchor' => 'last_activity_at',
                'action' => 'delete',
                // Shorter than a declined application because no decision was
                // ever made, so there is no decision to defend.
                'purpose' => 'Allowing an applicant to resume, and preventing duplicate intake.',
            ],

            'beneficiary_case_record' => [
                'label' => 'Approved beneficiary case record',
                'months' => 72,   // 6 years
                'anchor' => 'closed_at',
                'action' => 'de_identify',
                'purpose' => 'Financial, audit, tax, accountability and legal purposes where '
                    .'assistance was provided. De-identified rather than deleted so the '
                    .'financial trail survives while the person does not remain identifiable.',
            ],

            'beneficiary_sensitive_document' => [
                'label' => 'Medical and other highly sensitive supporting documents',
                'months' => 24,
                'anchor' => 'closed_at',
                'action' => 'delete',
                // Deliberately far shorter than the case record it belongs to.
                // A medical report proving eligibility has served its purpose
                // once the case closes; keeping it for six years alongside the
                // financial record would be retention without a purpose.
                'purpose' => 'Verification of eligibility at the time of assistance. Removed or '
                    .'de-identified once no longer necessary, unless lawful continued '
                    .'retention is required.',
                'sensitive' => true,
            ],

            'financial_record' => [
                'label' => 'Tax and accounting records',
                'months' => 72,   // 6 years, statutory minimum
                'anchor' => 'created_at',
                'action' => 'retain',
                // Never auto-deleted. Six years is a statutory MINIMUM, not a
                // deletion date, and destroying accounting records on a timer
                // is a bigger risk than keeping them.
                'purpose' => 'Statutory minimum retention for tax and accounting records. '
                    .'Deletion requires a documented decision, never a schedule.',
            ],

            'anonymised_statistics' => [
                'label' => 'Anonymised statistical data',
                'months' => null,   // indefinite
                'anchor' => null,
                'action' => 'retain',
                'purpose' => 'Long-term impact reporting. Carries no personal data by '
                    .'construction, so Act 843 retention limits do not bite.',
            ],
        ],

        /*
         * How long a de-identification or deletion is recorded for.
         *
         * The LOG outlives the data. Being able to show that a record was
         * destroyed, when, under which policy and by which run is the evidence
         * that the policy was followed — and it holds no personal data itself,
         * only a hashed reference.
         */
        'log_retention_months' => 120,

        /*
         * Records are not destroyed the instant they become due. A grace period
         * gives staff a window to place a hold if something has been missed,
         * and makes a misconfiguration recoverable rather than catastrophic.
         */
        'grace_period_days' => 30,

        /*
         * Safety valve. If a single run would destroy more than this many
         * records, it stops and reports instead. A retention job that suddenly
         * wants to delete ten thousand records is far more likely to be a bug
         * in an anchor date than a genuine backlog.
         */
        'max_records_per_run' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax deductibility — Act 896 ss.97 and 100
    |--------------------------------------------------------------------------
    |
    | Being incorporated or registered as a foundation does NOT make donations
    | deductible, and saying so would mislead donors into claims the GRA will
    | refuse. Deductibility messaging is therefore gated on a current written
    | GRA approval, held in `tax_approvals`, and is disabled automatically the
    | day that approval expires.
    |
    */
    'tax' => [

        // With no valid approval on file, every deductibility claim is
        // suppressed regardless of what any cause or CMS setting says.
        'require_gra_approval' => true,

        // Days before expiry to start warning administrators. An approval that
        // lapses unnoticed silently changes what the site is telling donors.
        'expiry_warning_days' => [90, 60, 30, 14, 7, 1],

        /*
         * Mandatory wording. A receipt may state that a donation was made to an
         * approved organisation; it may NOT state or imply that the donor's
         * deduction is guaranteed. Whether a deduction is allowed is the GRA's
         * determination, on the donor's own return.
         */
        'deduction_disclaimer' => 'This acknowledgement confirms the donation described above. '
            .'It does not guarantee that any deduction will be allowed. Any claim for a '
            .'deduction remains subject to the requirements and determination of the '
            .'Ghana Revenue Authority.',

        /*
         * A charitable acknowledgement is NEVER issued for a shop purchase.
         * A purchase is consideration for goods, not a gift, and issuing a
         * donation receipt for one would misrepresent the transaction to both
         * the customer and the GRA. Enforced in code, not by convention.
         */
        'never_acknowledge_payable_types' => [
            Order::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shop — approved categories and prohibited goods
    |--------------------------------------------------------------------------
    |
    | Shop sales and charitable donations are separate throughout: separate
    | accounting, separate receipts, separate payment records and separate
    | reporting. A purchase is not a gift and must never be reported as one.
    |
    */
    'shop' => [

        'approved_categories' => [
            'apparel' => [
                'label' => 'Apparel',
                'items' => ['Branded T-shirts', 'Polo shirts', 'Caps'],
            ],
            'bags-accessories' => [
                'label' => 'Bags & accessories',
                'items' => ['Tote bags', 'Wristbands', 'Keyholders'],
            ],
            'stationery' => [
                'label' => 'Stationery',
                'items' => ['Notebooks and journals', 'Pens', 'Calendars'],
            ],
            'drinkware' => [
                'label' => 'Drinkware',
                'items' => ['Mugs', 'Reusable water bottles'],
            ],
            'books-media' => [
                'label' => 'Books & publications',
                'items' => ['Books', 'Educational materials', 'Christian and devotional publications'],
            ],
            'gifts' => [
                'label' => 'Souvenirs & gifts',
                'items' => ['Foundation souvenirs', 'Approved gift items'],
            ],
            'campaign' => [
                'label' => 'Campaign merchandise',
                'items' => ['Campaign-specific fundraising merchandise'],
            ],
        ],

        /*
         * Regulated goods. Selling any of these needs a separate regulatory
         * review — FDA Ghana for medicines, supplements, food and cosmetics —
         * and none may be listed without one.
         *
         * Held as keywords so a product whose name or description trips one is
         * flagged for review rather than quietly published. Deliberately broad:
         * a false positive costs an editor thirty seconds, a false negative
         * costs the foundation its standing with a regulator.
         */
        'prohibited_keywords' => [
            'medicine', 'medicinal', 'drug', 'pharmaceutical', 'antibiotic',
            'tablet', 'capsule', 'syrup', 'vaccine', 'injection',
            'supplement', 'vitamin', 'herbal remedy', 'tonic',
            'cosmetic', 'cream', 'lotion', 'ointment', 'sanitiser', 'sanitizer',
            'food', 'snack', 'beverage', 'drink mix', 'water sachet',
            'medical device', 'thermometer', 'test kit', 'syringe',
        ],

        'prohibited_notice' => 'This product may fall under FDA Ghana regulation. '
            .'Medicines, regulated medical products, supplements, food and cosmetics '
            .'cannot be listed without a separate regulatory review.',
    ],
];
