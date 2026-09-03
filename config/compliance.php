<?php

declare(strict_types=1);
use App\Models\Order;

/*
|--------------------------------------------------------------------------
| Compliance policy
|--------------------------------------------------------------------------
|
| Retention periods, de-identification rules, tax-deductibility wording and the
| approved shop taxonomy.
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
|              necessary; destruction must prevent reconstruction in an
|              intelligible form; statistical/historical retention permitted
|              with adequate protection)
|   Act 896  — Income Tax Act, 2015, s.97 (approved charitable organisation)
|              and s.100 (contribution or donation to a worthwhile cause)
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
    |   retain        never swept; deletion requires a documented decision
    |
    | IMPORTANT — closure is not the trigger.
    | Marking a case closed STARTS the retention clock; it does not license
    | destruction. The record stays lawfully identifiable for the whole retention
    | period and is only de-identified once that period expires
    | (anchor + months + grace). A legal or audit hold overrides that date.
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

            /*
            |------------------------------------------------------------------
            | Volunteers
            |------------------------------------------------------------------
            |
            | ⚠ THE PERIODS BELOW ARE DEFAULTS, NOT ADVICE.
            |
            | Safeguarding records in particular are kept far longer in some
            | jurisdictions — precisely so an allegation made years later can be
            | investigated against what was known at the time. Six years after
            | a volunteer leaves is a defensible starting point for Ghana and is
            | what is set here, but it is a decision for the trustees with
            | advice, and it is flagged as an open question in
            | docs/PHASE-3-DATA-ARCHITECTURE.md rather than presented as settled.
            */
            'volunteer_application_declined' => [
                'label' => 'Declined volunteer application',
                'months' => 12,
                'anchor' => 'decided_at',
                'action' => 'delete',
                'purpose' => 'Defence of a complaint about the decision, and detection of a '
                    .'declined applicant reapplying under a different name.',
            ],

            'volunteer_application_withdrawn' => [
                'label' => 'Incomplete or withdrawn volunteer application',
                'months' => 6,
                'anchor' => 'last_activity_at',
                'action' => 'delete',
                'purpose' => 'Allowing an applicant to resume. No decision was made, so there '
                    .'is nothing to defend.',
            ],

            'volunteer_record' => [
                'label' => 'Volunteer record, including safeguarding checks',
                'months' => 72,   // 6 years after they leave
                'anchor' => 'ended_on',
                'action' => 'de_identify',
                'sensitive' => true,
                'purpose' => 'Safeguarding accountability. A concern raised after a volunteer '
                    .'has left must be answerable against what the foundation knew and checked '
                    .'at the time — which is impossible if the record has gone.',
            ],

            /*
            |------------------------------------------------------------------
            | Prayer requests
            |------------------------------------------------------------------
            |
            | Deliberately SHORT. A prayer request routinely carries the most
            | sensitive information anybody volunteers to this foundation — an
            | illness, a bereavement, a marriage in trouble — offered in
            | confidence and with no expectation that it is filed indefinitely.
            |
            | Twelve months is long enough to pray, to follow up, and to report
            | in aggregate. It is not long enough to become an archive of a
            | congregation's private difficulties.
            */
            'prayer_request' => [
                'label' => 'Prayer request',
                'months' => 12,
                'anchor' => 'created_at',
                'action' => 'delete',
                'sensitive' => true,
                'purpose' => 'Praying for the request and following it up pastorally. Offered in '
                    .'confidence, so kept only as long as that purpose lasts.',
            ],

            'event_registration' => [
                'label' => 'Event registration',
                'months' => 24,
                'anchor' => 'event_ended_at',
                'action' => 'delete',
                'purpose' => 'Attendance records for reporting and for contacting attendees about '
                    .'the same event series. Not a permanent mailing list — that needs its own '
                    .'consent.',
            ],

            /*
            |------------------------------------------------------------------
            | Communications
            |------------------------------------------------------------------
            |
            | Delivery logs hold an address, a phone number and, for
            | transactional mail, the rendered message — which for a case update
            | or a prayer follow-up is not a summary of personal data, it IS the
            | personal data.
            |
            | Two years, from the send, and then deleted. Long enough to answer
            | "did my receipt ever arrive?", to investigate a deliverability
            | problem, and to reconcile an SMS invoice. Not long enough to
            | become a searchable archive of everything the Foundation has ever
            | said to anybody.
            |
            | The financial trail does NOT depend on this: the donation and its
            | receipt are their own records under `financial_record`, and they
            | are the evidence that a gift was acknowledged. This log is
            | evidence of DELIVERY, which is a shorter-lived question.
            |
            | The suppression list is deliberately NOT swept — see the note on
            | App\Models\Suppression. Forgetting that somebody objected is how
            | they start receiving mail again after asking not to.
            */
            'communication_log' => [
                'label' => 'Email, SMS and notification delivery logs',
                'months' => 24,
                'anchor' => 'created_at',
                'action' => 'delete',
                'purpose' => 'Answering delivery queries, diagnosing deliverability problems and '
                    .'reconciling SMS invoices. Not a permanent archive of correspondence.',
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
    | Privacy — what survives de-identification, and in what form
    |--------------------------------------------------------------------------
    |
    | Act 843 defines personal data broadly enough to cover a person who is
    | identifiable from the retained data itself OR from that data combined with
    | other information the Foundation holds, or is likely to hold. Stripping the
    | name is therefore not sufficient: a row reading
    |
    |     Legacy of Love / Widower Support / GHS 4,735 / 13 March 2026 / Tamale
    |
    | can single out one person with no name anywhere in it.
    |
    | So the boundary below has three dispositions, not two:
    |
    |   destroy      overwritten irreversibly, then nulled
    |   generalise   kept, but coarsened (bands and periods, never exact values)
    |   keep         kept as-is; safe at population scale
    |
    | Every column on a de-identifiable model must map to one of these elements.
    | A column mapping to nothing fails a test — which is the point: the
    | dangerous case is not a wrong decision, it is a column added in two years
    | that nobody classified at all.
    |
    */
    'privacy' => [

        /*
         * Direct and indirect identifiers, and what happens to each once the
         * retention period expires.
         */
        'elements' => [

            // --- Destroy: direct identifiers and identifying free text -------
            'name' => ['label' => 'Name and aliases', 'disposition' => 'destroy'],
            'phone' => ['label' => 'Phone number', 'disposition' => 'destroy'],
            'email' => ['label' => 'Email address', 'disposition' => 'destroy'],
            'national_id' => ['label' => 'Ghana Card, passport or other ID number', 'disposition' => 'destroy'],
            'id_document' => ['label' => 'Scanned identity document', 'disposition' => 'destroy'],
            'date_of_birth' => ['label' => 'Full date of birth', 'disposition' => 'destroy'],
            'address' => ['label' => 'Residential or postal address', 'disposition' => 'destroy'],
            'geolocation' => ['label' => 'GPS or precise location', 'disposition' => 'destroy'],
            'community' => ['label' => 'Village or community', 'disposition' => 'destroy'],
            'likeness' => ['label' => 'Photograph, video or voice recording', 'disposition' => 'destroy'],
            'signature' => ['label' => 'Signature', 'disposition' => 'destroy'],
            'bank_details' => ['label' => 'Bank or mobile money account details', 'disposition' => 'destroy'],
            'next_of_kin' => ['label' => 'Emergency contact or next of kin', 'disposition' => 'destroy'],
            'household' => ['label' => 'Names and details of household members', 'disposition' => 'destroy'],
            'medical' => ['label' => 'Medical reports and diagnoses', 'disposition' => 'destroy'],
            'religion' => ['label' => 'Religious information', 'disposition' => 'destroy'],
            'school_employer' => ['label' => 'School or employer where identifying', 'disposition' => 'destroy'],
            'narrative' => ['label' => 'Free-text application narrative', 'disposition' => 'destroy'],
            'case_notes' => ['label' => 'Case-worker notes', 'disposition' => 'destroy'],
            'supporting_document' => ['label' => 'Uploaded supporting document', 'disposition' => 'destroy'],
            'device' => ['label' => 'IP address or device identifier', 'disposition' => 'destroy'],

            // Destroyed because it links back to the original case. A reference
            // kept "for traceability" is exactly the linkage that makes
            // everything else pseudonymous rather than anonymous.
            'case_reference' => ['label' => 'Case reference number', 'disposition' => 'destroy'],

            // Never carried into the analytics dataset. It resolves to a
            // transaction, which resolves to a person.
            'payment_reference' => ['label' => 'Payment or Paystack reference', 'disposition' => 'destroy'],

            // --- Generalise: useful, but identifying at full precision -------
            'assistance_amount' => [
                'label' => 'Assistance amount',
                'disposition' => 'generalise',
                'method' => 'amount_band',
            ],
            'assistance_date' => [
                'label' => 'Assistance date',
                'disposition' => 'generalise',
                'method' => 'period',
            ],
            'age' => [
                'label' => 'Age',
                'disposition' => 'generalise',
                'method' => 'age_band',
            ],

            // Kept, but subject to the minimum-group rule below: a programme
            // with three beneficiaries in it identifies all three.
            'programme' => [
                'label' => 'Programme or category',
                'disposition' => 'generalise',
                'method' => 'passthrough',
            ],

            // --- Keep: safe at population scale ------------------------------
            'division' => ['label' => 'Division', 'disposition' => 'keep'],
            'region' => ['label' => 'Region', 'disposition' => 'keep'],
            'district' => ['label' => 'District', 'disposition' => 'keep'],
            'outcome' => ['label' => 'Broad coded outcome', 'disposition' => 'keep'],
            'gender' => ['label' => 'Gender', 'disposition' => 'keep'],
            'indicator' => ['label' => 'Statistical or impact indicator', 'disposition' => 'keep'],
        ],

        /*
         * Age bands. Aligned to how the Foundation actually reports — early
         * childhood, primary, JHS, SHS and young adult, then decades.
         */
        'age_bands' => [
            [0, 5], [6, 12], [13, 17], [18, 24],
            [25, 34], [35, 44], [45, 54], [55, 64], [65, null],
        ],

        /*
         * Assistance amount bands, in integer pesewas (GHS x 100), matching the
         * money rule used everywhere else in this application.
         */
        'amount_bands' => [
            [0, 9999],              // up to GHS 100
            [10000, 49999],         // GHS 100 - 500
            [50000, 99999],         // GHS 500 - 1,000
            [100000, 249999],       // GHS 1,000 - 2,500
            [250000, 499999],       // GHS 2,500 - 5,000
            [500000, 999999],       // GHS 5,000 - 10,000
            [1000000, null],        // above GHS 10,000
        ],

        /*
         * Date precision in the analytics dataset. An exact day plus a division
         * plus a district is frequently unique; a month is not.
         *
         * One of: month, quarter, year.
         */
        'date_granularity' => 'month',

        /*
         * The finest geography that may appear in analytics. Region and district
         * are populous enough; a village or community is not.
         */
        'geography_max_level' => 'district',

        /*
         * Minimum group size for any published or exported statistical
         * breakdown. A cell covering fewer than this many beneficiaries is
         * suppressed rather than shown.
         *
         * Not a figure mandated by Act 843 — it is a disclosure control that
         * reduces singling-out risk, and it matters here because the Foundation
         * works with small populations in sensitive categories (health, orphan
         * status, widow and widower support).
         */
        'minimum_group_size' => 5,

        /*
         * Hashing a Ghana Card number, phone number or case reference does NOT
         * make a record anonymous while the Foundation still holds any practical
         * means of reversing it — a lookup table, a key, or the source record
         * itself. That is pseudonymisation, and pseudonymised data is still
         * personal data under Act 843.
         *
         * The design consequence, enforced in the analytics dataset: once the
         * retention period expires there is NO reversible linkage back to the
         * beneficiary at all. Not a hash, not an encrypted id, nothing.
         */
        'allow_reversible_pseudonyms_post_retention' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Safeguarding
    |--------------------------------------------------------------------------
    |
    | The foundation works with orphans, vulnerable children, widows and the
    | elderly. Two of its four divisions exist to do so. That makes safeguarding
    | a structural concern of this application rather than a policy document
    | filed somewhere.
    |
    | The rule below is the one that matters: a volunteer role involving
    | unsupervised contact with children or other vulnerable people cannot be
    | approved until every required check is recorded. Not "should not" —
    | `VolunteerApplication::approve()` refuses.
    |
    | ⚠ WHAT THIS DOES NOT DO. It does not tell the foundation which checks
    | Ghanaian law requires, or who is competent to sign them off. Those are
    | questions for the trustees with advice from the Department of Social
    | Welfare. What it does is make the answer enforceable once it is known, and
    | refuse to proceed without one in the meantime.
    |
    */
    'safeguarding' => [

        /*
         * Checks required before a role with vulnerable-person contact may be
         * approved. Each is recorded against the application with a reference
         * and a date, so "was this checked?" has an evidenced answer.
         */
        'required_checks' => [
            'declaration' => [
                'label' => 'Signed safeguarding declaration',
                'description' => 'The applicant has read the safeguarding policy, disclosed any '
                    .'relevant convictions, and signed to that effect.',
            ],
            'police_clearance' => [
                'label' => 'Ghana Police Service criminal record check',
                'description' => 'A police clearance certificate obtained for this applicant. '
                    .'The reference recorded is the certificate number.',
            ],
            'reference_one' => [
                'label' => 'First reference, taken up',
                'description' => 'A reference actually contacted and spoken to — not merely a '
                    .'name and number supplied by the applicant.',
            ],
            'reference_two' => [
                'label' => 'Second reference, taken up',
                'description' => 'A second, independent reference. Two because one referee can '
                    .'be a friend; two who do not know each other rarely both are.',
            ],
            'interview' => [
                'label' => 'Face-to-face interview',
                'description' => 'Conducted by someone other than the person who recruited them.',
            ],
        ],

        /*
         * Checks required for a role with NO vulnerable-person contact — a
         * one-off event steward, a driver, someone folding leaflets.
         *
         * Deliberately lighter. Requiring a police check to hand out flyers
         * would mean the foundation either never recruits anybody or starts
         * treating the requirement as a formality, and a formality is not a
         * safeguard.
         */
        'basic_checks' => [
            'declaration',
        ],

        /*
         * How long a police clearance is treated as current.
         *
         * A certificate is a statement about a point in time, not a permanent
         * property of a person. Two years is the default; the model re-flags a
         * volunteer whose clearance has gone stale rather than assuming a check
         * done once holds for ever.
         */
        'clearance_valid_months' => 24,

        /*
         * A concern about a volunteer suspends them IMMEDIATELY, before any
         * investigation. Not a punishment and not a finding — a precaution,
         * and the order of events that any safeguarding policy worth having
         * insists on.
         */
        'suspend_on_concern' => true,
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
    | The GRA does not prescribe mandatory receipt wording. What s.100 requires
    | is a written acknowledgement from a verifiable beneficiary, which the donor
    | submits with their own claim; for the charitable-organisation route the
    | recipient must hold an unexpired written approval issued by the
    | Commissioner-General under s.97. The wording below states those two facts
    | separately and does not conflate them.
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
         * The document is an ACKNOWLEDGEMENT, not a "tax-deductible receipt".
         * The Foundation acknowledges a contribution; the deduction is the
         * donor's separate claim, decided by the GRA. Naming the document
         * accurately is the first place that distinction is either kept or lost.
         */
        'acknowledgement' => [

            'title' => 'ACKNOWLEDGEMENT OF CONTRIBUTION/DONATION TO A WORTHWHILE CAUSE',

            /*
             * Paragraph 1 — the Foundation's s.97 status.
             *
             * Rendered ONLY while a valid s.97 approval is held. Until the
             * Notice of Approval is actually in hand there is nothing true to
             * say here, so nothing is said.
             */
            'approval_paragraph' => ':organisation is a charitable organisation approved by the '
                .'Commissioner-General of the Ghana Revenue Authority under section 97 of the '
                .'Income Tax Act, 2015 (Act 896), pursuant to Notice of Approval :reference, '
                .'valid from :issued_on to :expires_on.',

            // The Commissioner-General issues an approval for a specified
            // period, but an approval carrying no stated expiry must still be
            // citable without inventing one.
            'approval_paragraph_open' => ':organisation is a charitable organisation approved by the '
                .'Commissioner-General of the Ghana Revenue Authority under section 97 of the '
                .'Income Tax Act, 2015 (Act 896), pursuant to Notice of Approval :reference, '
                .'issued on :issued_on.',

            /*
             * Paragraph 2 — the acknowledgement itself. "The Foundation" is a
             * back-reference to paragraph 1 and is deliberately not repeated in
             * full.
             */
            'receipt_paragraph' => 'The Foundation hereby acknowledges receipt from :donor of a '
                .'contribution/donation in the amount of :amount (:amount_in_words) on :date, '
                .'made towards :cause.',

            /*
             * Paragraph 3 — mandatory, never omitted.
             *
             * States the purpose (s.100 evidence) and, in the same breath, that
             * eligibility and allowance are the GRA's determination. A receipt
             * may confirm the gift; it may not promise the deduction.
             */
            'disclaimer' => 'This acknowledgement is issued as evidence of a contribution/donation '
                .'to a worthwhile cause for purposes of section 100 of the Income Tax Act, 2015 '
                .'(Act 896). Eligibility for and allowance of any deduction remains subject to the '
                .'applicable requirements and determination of the Ghana Revenue Authority.',

            /*
             * Everything the document must carry. The GRA's own claim form asks
             * the donor for the worthwhile cause, the beneficiary, the
             * beneficiary's TIN and the amount in GHS, and requires this
             * acknowledgement to accompany the application — so the document has
             * to supply all of it.
             *
             * Enforced when an acknowledgement is generated: a missing field is
             * a refusal to issue, not a blank line on a legal document.
             */
            'required_fields' => [
                'receipt_number' => 'Unique acknowledgement number',
                'issued_on' => 'Date of issue',
                'donor_name' => 'Donor name',
                'organisation_name' => 'Beneficiary organisation (legal name)',
                'organisation_tin' => 'Foundation TIN',
                'amount' => 'Amount in GHS',
                'amount_in_words' => 'Amount in words',
                'donated_on' => 'Date of the contribution',
                'cause' => 'Worthwhile cause (division, project or campaign)',
                'payment_reference' => 'Payment reference',
                'approval_reference' => 'GRA section 97 approval reference',
                'approval_validity' => 'Approval validity dates',
                'authentication' => 'Authorised signature or seal',
            ],
        ],

        /*
         * Kept for anything needing the bare disclaimer without the full
         * document — a donation form footnote, a cause page.
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
