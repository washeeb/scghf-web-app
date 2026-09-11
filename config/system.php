<?php

declare(strict_types=1);
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| System
|--------------------------------------------------------------------------
|
| The audit trail, API tokens, error capture, backup verification and the
| aggregate-only visitor counters.
|
| Two positions in this file are worth reading before changing anything.
|
| 1. THE AUDIT LOG RECORDS READS, NOT JUST WRITES.
|    spatie/laravel-activitylog already records model changes. This records
|    actions — viewing a beneficiary's medical history, exporting four thousand
|    donor records, running the retention sweep. None of those change anything,
|    so none of them appear anywhere today; and for a foundation holding files
|    on vulnerable children, "who read this?" is the more serious question.
|
| 2. VISITOR STATISTICS ARE AGGREGATE BY CONSTRUCTION.
|    There is no per-visitor row, no IP, no fingerprint, no cross-site
|    identifier — not as a policy that could be relaxed, but because the table
|    has nowhere to put one. The cost is that "unique visitors" is not a number
|    this application can produce. That is the trade, and it is deliberate.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    */
    'audit' => [

        /*
         * Events that MUST be recorded, grouped by category and severity.
         *
         * Declared here rather than passed at each call site so that the set of
         * audited actions is one readable list somebody can check against a
         * policy — and so an event that should be audited but is missing from
         * it is visible by its absence.
         *
         * Recording an unlisted event throws. That is the point: a new export
         * screen added in a year must be classified before it can log, rather
         * than logging as `unknown` and being invisible in every report.
         */
        'events' => [

            // --- Authentication and access ---------------------------------
            'auth.login' => ['category' => 'auth', 'severity' => 'info'],
            'auth.login_failed' => ['category' => 'auth', 'severity' => 'notice'],
            'auth.logout' => ['category' => 'auth', 'severity' => 'info'],
            'auth.password_reset' => ['category' => 'auth', 'severity' => 'notice'],
            'auth.two_factor_disabled' => ['category' => 'security', 'severity' => 'warning'],
            'auth.locked_out' => ['category' => 'security', 'severity' => 'warning'],

            /*
             * Public account lifecycle.
             *
             * `account_created` is separate from `auth.login` because it is a
             * different fact: an account existing is what a later sign-in is
             * evidence about, and a registration wave is only visible if it can
             * be counted on its own.
             *
             * `password_changed` is separate from `password_reset` for the same
             * reason. A reset is somebody who could not get in; a change is
             * somebody who was already in — and the second one, unexpected, is
             * what an account takeover looks like from the outside.
             */
            'auth.account_created' => ['category' => 'auth', 'severity' => 'info'],
            'auth.password_changed' => ['category' => 'auth', 'severity' => 'notice'],

            /*
             * Changing the address on an account, in three parts.
             *
             * Audited separately from one another because they answer different
             * questions. A REQUEST that was never confirmed is the trace an
             * attempted takeover leaves; a CANCELLATION is somebody saying it
             * was not them, which is a security incident rather than a tidy-up;
             * and the CHANGE itself is the moment password resets started going
             * somewhere else.
             *
             * `warning` on the cancellation because it is only ever reached by
             * an account holder who has just found out somebody else is inside.
             */
            'auth.email_change_requested' => ['category' => 'auth', 'severity' => 'notice'],
            'auth.email_changed' => ['category' => 'security', 'severity' => 'warning'],
            'auth.email_change_cancelled' => ['category' => 'security', 'severity' => 'warning'],

            /*
             * `auth.two_factor_disabled` already existed here, declared since
             * Module 8 with nothing recording it. This is what records it, and
             * the ON direction is added beside it so the pair reads as a pair.
             */
            'auth.two_factor_enabled' => ['category' => 'security', 'severity' => 'notice'],

            /*
             * Impersonation. Always at least a warning, never info: an
             * administrator acting as somebody else is a serious capability
             * even when the reason is good.
             */
            'admin.impersonation_started' => ['category' => 'security', 'severity' => 'warning'],
            'admin.impersonation_ended' => ['category' => 'security', 'severity' => 'notice'],

            // --- Reading personal data -------------------------------------
            // The events that leave no other trace anywhere.
            'beneficiary.viewed' => ['category' => 'data_access', 'severity' => 'notice'],
            'beneficiary.document_downloaded' => ['category' => 'data_access', 'severity' => 'warning'],
            'donor.pii_viewed' => ['category' => 'data_access', 'severity' => 'info'],
            'volunteer.pii_viewed' => ['category' => 'data_access', 'severity' => 'info'],
            'safeguarding.record_viewed' => ['category' => 'safeguarding', 'severity' => 'warning'],
            'prayer_request.viewed' => ['category' => 'data_access', 'severity' => 'notice'],

            /*
             * Replacing the file behind a media row.
             *
             * Audited because nothing else records it. The row keeps its id, so
             * every one of the thirty-odd references to it keeps pointing at
             * the same place and none of those records changes — yet what a
             * consent's evidence, or a beneficiary's ID document, actually SHOWS
             * is now a different image. That is precisely the shape of change
             * the audit trail exists to catch: real, invisible everywhere else.
             */
            'media.replaced' => ['category' => 'data_access', 'severity' => 'notice'],

            // --- Taking personal data out of the system --------------------
            // Always at least a warning. An export leaves the application's
            // protections behind and lands in somebody's Downloads folder.
            'donors.exported' => ['category' => 'data_export', 'severity' => 'warning'],
            'donations.exported' => ['category' => 'data_export', 'severity' => 'warning'],
            'beneficiaries.exported' => ['category' => 'data_export', 'severity' => 'critical'],
            'volunteers.exported' => ['category' => 'data_export', 'severity' => 'warning'],
            'subscribers.exported' => ['category' => 'data_export', 'severity' => 'warning'],

            /*
             * The contact inbox holds names, email addresses, phone numbers and
             * whatever somebody chose to write — which, for a foundation, is
             * sometimes a disclosure. A warning like the rest, and not `info`:
             * an export of it is an export of personal data even though the
             * table is not called "donors".
             */
            'contact_messages.exported' => ['category' => 'data_export', 'severity' => 'warning'],

            /*
             * `report.generated` covers exports of CONTENT — the FAQ list, the
             * redirect table, the news index. Recorded, because a complete
             * export is still worth being able to see in the trail, but `info`
             * rather than `warning`: nothing in them is personal data, and
             * flagging them at the same level as a donor export is how a log
             * stops being read.
             */
            'report.generated' => ['category' => 'data_export', 'severity' => 'info'],

            // --- Money -----------------------------------------------------
            'donation.recorded_offline' => ['category' => 'money', 'severity' => 'notice'],
            'donation.marked_needs_review' => ['category' => 'money', 'severity' => 'warning'],
            'refund.requested' => ['category' => 'money', 'severity' => 'warning'],
            'refund.approved' => ['category' => 'money', 'severity' => 'critical'],
            'receipt.issued' => ['category' => 'money', 'severity' => 'info'],
            // A receipt leaving the application as a PDF: a name and an amount
            // on a document. Recorded like a CSV export, because it is one.
            'receipt.downloaded' => ['category' => 'data_export', 'severity' => 'info'],
            'receipt.resent' => ['category' => 'money', 'severity' => 'notice'],
            'refund.processed' => ['category' => 'money', 'severity' => 'critical'],
            'refund.failed' => ['category' => 'money', 'severity' => 'warning'],
            'donation.reconciled' => ['category' => 'money', 'severity' => 'info'],
            'donation.note_added' => ['category' => 'money', 'severity' => 'info'],
            'donor.merged' => ['category' => 'data_access', 'severity' => 'warning'],

            /*
             * Money leaving the foundation. Critical on approval rather than on
             * payment: approval is the decision, and the decision is the thing
             * an auditor traces back to a person.
             */
            'payout.approved' => ['category' => 'money', 'severity' => 'critical'],
            'payout.paid' => ['category' => 'money', 'severity' => 'warning'],
            'pledge.recorded' => ['category' => 'money', 'severity' => 'info'],
            'receipt.reissued' => ['category' => 'money', 'severity' => 'warning'],
            'reconciliation.run' => ['category' => 'money', 'severity' => 'info'],

            // --- Privacy ---------------------------------------------------
            'retention.executed' => ['category' => 'privacy', 'severity' => 'critical'],
            'retention.dry_run' => ['category' => 'privacy', 'severity' => 'info'],
            'legal_hold.placed' => ['category' => 'privacy', 'severity' => 'warning'],
            'legal_hold.lifted' => ['category' => 'privacy', 'severity' => 'warning'],
            'erasure.requested' => ['category' => 'privacy', 'severity' => 'warning'],
            'erasure.completed' => ['category' => 'privacy', 'severity' => 'critical'],
            'consent.revoked' => ['category' => 'privacy', 'severity' => 'notice'],
            'suppression.released' => ['category' => 'privacy', 'severity' => 'warning'],

            // --- Configuration ---------------------------------------------
            'setting.changed' => ['category' => 'config', 'severity' => 'notice'],
            'feature_flag.changed' => ['category' => 'config', 'severity' => 'warning'],
            'role.permissions_changed' => ['category' => 'security', 'severity' => 'warning'],
            'user.role_assigned' => ['category' => 'security', 'severity' => 'warning'],
            'tax_approval.recorded' => ['category' => 'config', 'severity' => 'critical'],

            // --- Tokens and integrations -----------------------------------
            'api_token.created' => ['category' => 'security', 'severity' => 'warning'],
            'api_token.revoked' => ['category' => 'security', 'severity' => 'notice'],
            'api_token.rejected' => ['category' => 'security', 'severity' => 'warning'],

            // --- Backups ---------------------------------------------------
            'backup.completed' => ['category' => 'config', 'severity' => 'info'],
            'backup.failed' => ['category' => 'config', 'severity' => 'critical'],
            'backup.restore_tested' => ['category' => 'config', 'severity' => 'notice'],
        ],

        /*
         * A single action touching more than this many records is escalated to
         * `critical` whatever its declared severity.
         *
         * The number that turns routine into incident. One donor record viewed
         * is somebody doing their job; two thousand exported is a question that
         * needs asking the same day.
         */
        'bulk_threshold' => 100,

        /*
         * Audit entries are NEVER swept by the retention runner.
         *
         * They are the evidence that the retention policy was followed, and a
         * retention policy that deletes its own evidence is not one anybody can
         * demonstrate. They hold no case detail — an actor, an event, a count —
         * so the Act 843 minimisation argument holds.
         *
         * Kept for reference and for the archive tooling that will eventually
         * move old years out of the live table.
         */
        'retain_years' => 7,

        /*
        |----------------------------------------------------------------------
        | Archiving (open question 16, answered)
        |----------------------------------------------------------------------
        |
        | Kept for seven years and never swept — so on shared hosting this
        | becomes the largest table in the database, dragged across a slow
        | connection by every nightly backup.
        |
        | The answer is not to delete it. Whole CLOSED years are written to a
        | compressed, hash-verified file and removed from the live table,
        | leaving an `audit_archives` row that proves what was moved and lets
        | the chain continue across the gap. A gap with no archive row is still
        | reported as tampering, which is what should happen when somebody
        | deletes a year by hand.
        |
        | Two full years stay live because that is the window in which anybody
        | actually searches them — an incident investigation looks at the last
        | few months, and a data-subject request at the last year or two.
        */
        'archive_after_years' => (int) env('AUDIT_ARCHIVE_AFTER_YEARS', 2),

        /*
         * Where archives are written. `local` is storage/app, which is OUTSIDE
         * the web root and therefore not reachable over HTTP — an audit archive
         * behind a guessable URL would be a worse leak than the table it came
         * from. Point this at an off-server disk once one exists.
         */
        'archive_disk' => env('AUDIT_ARCHIVE_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API tokens
    |--------------------------------------------------------------------------
    */
    'api' => [

        /*
         * Nothing lives for ever. A token with no expiry is a permanent
         * credential nobody remembers issuing, in a config file on a laptop
         * that left the organisation three years ago.
         */
        'max_token_lifetime_days' => 365,
        'default_token_lifetime_days' => 90,

        /*
         * Every ability a token can be granted. A token asking for one not on
         * this list is refused at creation — abilities are not free text, and a
         * typo'd ability that silently grants nothing is worse than an error,
         * because it looks like it works until the day it matters.
         */
        'abilities' => [
            'donations:read' => 'Read donation totals and summaries',
            'causes:read' => 'Read causes and their progress',
            'projects:read' => 'Read projects and updates',
            'events:read' => 'Read the events calendar',
            'shop:read' => 'Read the product catalogue',
            'impact:read' => 'Read published impact statistics',
            'subscribe:write' => 'Add a newsletter subscriber (double opt-in still applies)',
            'contact:write' => 'Submit a contact enquiry',
        ],

        /*
         * Abilities that may NEVER be granted to a token.
         *
         * Held as a list rather than by omission so the refusal is explicit.
         * Anything touching beneficiaries, safeguarding or money movement is a
         * decision a person makes while logged in, not something an integration
         * does unattended.
         */
        'forbidden_ability_prefixes' => [
            'beneficiaries', 'safeguarding', 'donors', 'refunds', 'users', 'roles', 'settings',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | `MEDIA_STRIP_EXIF` has been in .env.example since Phase 2, annotated
    | "GPS in beneficiary photos — never optional", with nothing reading it.
    | Now something does.
    |
    | ⚠ Turning this off does not make publishing easier. It makes it
    | IMPOSSIBLE.
    |
    | `Media::isPublishable()` refuses any image that has not been sanitised, so
    | switching this off stops files being stripped and therefore stops them
    | being published. That is deliberate: the reason somebody would reach for
    | this switch is an upload failing, and the wrong fix for a failing upload
    | is to publish photographs with coordinates in them.
    |
    | The only legitimate use is diagnosing a broken GD installation. Images
    | uploaded while it is off stay unpublishable until it is back on and
    | `scghf:strip-media-metadata --execute` has run over them.
    */
    'media' => [
        'strip_exif' => env('MEDIA_STRIP_EXIF', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error reporting
    |--------------------------------------------------------------------------
    */
    'errors' => [

        /*
         * Errors are GROUPED by fingerprint, not stored one row per occurrence.
         *
         * Ten thousand copies of the same undefined-index is one problem, and
         * on a shared plan with an inode quota it is also a full disk. A group
         * with a count is more useful and costs almost nothing.
         */
        'group_window_hours' => 24,

        /*
         * Ceiling on distinct groups held. Oldest resolved groups are pruned
         * first. A safety valve for the shared-hosting disk quota, not a
         * retention policy.
         */
        'max_groups' => 500,

        /*
         * Exception classes not worth recording. All of them are ordinary
         * traffic — a bot probing for /wp-login.php is not an application
         * error, and a table full of them hides the one that is.
         */
        'ignore' => [
            AuthenticationException::class,
            AuthorizationException::class,
            ModelNotFoundException::class,
            TokenMismatchException::class,
            ValidationException::class,
            NotFoundHttpException::class,
            MethodNotAllowedHttpException::class,
        ],

        /*
         * Request keys never stored, at any nesting depth.
         *
         * PCI DSS SAQ-A says this application never touches card data; an error
         * report that captured a request body would quietly make that untrue.
         * The same applies to a beneficiary's narrative arriving in a failed
         * form submission.
         */
        'never_capture' => [
            'password', 'password_confirmation', 'current_password',
            'card', 'card_number', 'cvv', 'cvc', 'pin', 'expiry',
            'token', 'api_key', 'secret', 'authorization',
            'ghana_card_number', 'national_id', 'date_of_birth',
            'narrative', 'medical_notes', 'case_notes', 'request',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | ⚠ A backup that has never been restored is a hypothesis.
    |
    | `CLAUDE.md` lists this among the things commonly forgotten, and it is the
    | reason `backups_log` records RESTORE TESTS as first-class rows rather than
    | only recording that a backup ran. Every one of them completing
    | successfully tells you nothing about whether the foundation could actually
    | get its data back.
    */
    'backups' => [

        /*
         * How often a restore must actually be performed and verified. Past
         * this, the dashboard says so and keeps saying so.
         */
        'restore_test_interval_days' => 90,

        /*
         * How long without a successful backup before it is treated as an
         * incident rather than a blip.
         */
        'stale_after_hours' => 36,

        /*
         * Shared hosting counts inodes, not just bytes, and a media library
         * plus a month of daily archives is how an account hits the limit. The
         * dashboard warns before the host does.
         */
        'warn_above_bytes' => 2 * 1024 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Visitor statistics
    |--------------------------------------------------------------------------
    |
    | Aggregate counts only. No IP address, no fingerprint, no cross-site
    | identifier, no per-visitor row — not as a policy, but because the schema
    | has nowhere to put one.
    |
    | The consequence, stated plainly because somebody will ask for it: this
    | application cannot report unique visitors. It reports views, and sessions
    | counted from the session cookie the application already sets for its own
    | reasons. Producing a unique-visitor number would mean creating an
    | identifier for people who did not ask to be counted, and that is a bigger
    | cost than the number is worth.
    */
    'visitors' => [

        'enabled' => env('VISITOR_STATS_ENABLED', true),

        /*
         * Dimensions recorded, each as its own aggregate row per day.
         *
         * `device_type` is the coarse class the user agent already announces —
         * mobile, tablet or desktop. It is not a fingerprint, and it matters
         * here: this foundation's readers are disproportionately on low-end
         * Android handsets, and knowing that is what justifies the performance
         * budget.
         */
        'dimensions' => ['path', 'referrer_host', 'device_type'],

        /*
         * Paths never counted. Admin screens are staff activity, not audience,
         * and counting them would make the numbers describe the foundation
         * rather than its visitors.
         */
        'ignore_paths' => ['admin/*', 'livewire/*', 'up', 'webhooks/*', 'storage/*'],

        // Bots inflate every number they touch. Matched on the user agent only,
        // which is nothing more than what the request already announced.
        'ignore_bots' => true,

        /*
         * Distinct values kept per dimension per day. A site scraped by a
         * misbehaving crawler can otherwise produce ten thousand distinct
         * paths in an afternoon, and the table is not the place to absorb that.
         */
        'max_values_per_dimension' => 200,
    ],
];
