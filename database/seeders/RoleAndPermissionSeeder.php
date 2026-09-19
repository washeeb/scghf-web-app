<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The capability matrix from PHASE-1-BLUEPRINT.md §7.1, as data.
 *
 * Two rules this encodes, both from CLAUDE.md:
 *
 *   1. Permissions are checked, never roles. Code says `@can('donations.refund')`,
 *      never `@role('finance')`. That is why this file grants permissions TO
 *      roles rather than scattering role names through the application — the
 *      matrix can change without touching code.
 *
 *   2. Super Admin gets a wildcard via Gate::before, NOT every permission
 *      individually. Enumerating them means a permission added later is
 *      silently missing from the one role that must never lack it.
 *
 * Idempotent: safe to re-run on every deploy, which is how new permissions
 * reach production.
 */
class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Every permission in the system, grouped by area.
     *
     * @var array<string, array<int, string>>
     */
    private const PERMISSIONS = [
        'content' => [
            'pages.view', 'pages.create', 'pages.update', 'pages.delete', 'pages.publish',
            'menus.manage',
            'blog.view', 'blog.create', 'blog.update', 'blog.delete', 'blog.publish',
            'faqs.manage', 'testimonials.manage', 'partners.manage', 'galleries.manage',
            'documents.manage', 'announcements.manage', 'redirects.manage', 'seo.manage',
            'media.view', 'media.upload', 'media.delete',
            'revisions.restore',
        ],
        'programmes' => [
            'divisions.manage',
            'projects.view', 'projects.create', 'projects.update', 'projects.delete',
            'causes.view', 'causes.create', 'causes.update', 'causes.delete',
            'impact.view', 'impact.manage',
            // Beneficiary records hold data about children. Separated from
            // ordinary content permissions so a Content Editor cannot reach
            // them by accident.
            'beneficiaries.view', 'beneficiaries.manage',
            /*
             * Wave 2, case management. `view` is Tier A of the design note
             * (the case exists, where it is, who has it); `manage` creates
             * and works cases; `view_sensitive` is Tier B and C on EVERY
             * case, which only the Safeguarding Lead holds — a Programme
             * Officer sees Tier B and C on the cases they are the worker
             * for, through the policy, not through a permission.
             * `view_financial` is what Finance needs to pay an approved
             * case and nothing else; `audit` is the process without the
             * person; `export` is the one CSV of children's names, held by
             * the data-protection lead alone and never by wildcard.
             */
            'beneficiaries.view_sensitive', 'beneficiaries.view_financial',
            'beneficiaries.audit', 'beneficiaries.export',
            'consents.view', 'consents.manage',
            'stories.publish',

            /*
             * Sponsorship gets its own permissions rather than riding on
             * `beneficiaries`.
             *
             * It is the one feature that deliberately discloses information
             * about a child to somebody outside the foundation, so who may
             * arrange one should be a decision somebody made — not a capability
             * inherited by everybody who can open a case file.
             */
            'sponsorships.view', 'sponsorships.manage',
        ],
        'fundraising' => [
            'donations.view', 'donations.view_pii', 'donations.export',
            'donations.record_offline', 'donations.receipt_reissue',
            'donations.refund', 'donations.refund_over_limit',
            'donors.view', 'donors.update', 'donors.merge',
            'subscriptions.view', 'subscriptions.manage',
            'payments.view_transactions', 'payments.replay_webhook',
            'payments.reconcile', 'payments.view_keys',
            'fundraisers.moderate',
            'pledges.view', 'pledges.manage',
            // Grants and institutional funders (Wave 2): internal records of
            // where the larger money comes from and the deadlines that lose it.
            'grants.view', 'grants.manage',

            /*
             * Payouts are split three ways on purpose.
             *
             * Requesting and approving are separate permissions because they
             * must be held by different people — `Payout::approve()` refuses a
             * self-approval outright, and giving one person both permissions
             * would only let them discover that at the worst moment.
             */
            'payouts.view', 'payouts.request', 'payouts.approve', 'payouts.mark_paid',
        ],
        'shop' => [
            'products.view', 'products.create', 'products.update', 'products.delete',
            'inventory.manage',
            'orders.view', 'orders.fulfil', 'orders.refund_request',
            'coupons.manage', 'shipping.manage',
            'reviews.moderate',
        ],
        'engagement' => [
            'volunteers.view', 'volunteers.manage', 'volunteers.view_pii', 'volunteers.log_hours',
            'events.view', 'events.manage', 'events.view_registrations',
            'contact.view', 'contact.reply', 'contact.view_safeguarding',
            'newsletter.view', 'newsletter.draft', 'newsletter.send',
            'prayer_requests.view',
        ],
        'communications' => [
            'templates.email.manage', 'templates.sms.manage',
            'logs.email.view', 'logs.sms.view',

            /*
             * Releasing a suppression is its own permission, separate from
             * reading the list.
             *
             * Putting an address back into circulation after a hard bounce or a
             * complaint is what gets a sending domain blocklisted, and
             * `Suppression::release()` already demands a named person and a
             * reason. Granting that with the same permission that lets somebody
             * look at the list would make the recorded decision meaningless.
             */
            'suppressions.view', 'suppressions.release',

            // The outbox. Cancelling a queued message is not the same as
            // reading the queue — a campaign mid-send can be stopped by it.
            'messages.view', 'messages.cancel',
        ],
        'system' => [
            'admin.access',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.manage',
            'settings.manage', 'appearance.manage', 'theme.colours.manage',
            'feature_flags.manage', 'maintenance.toggle',
            'backups.run', 'backups.restore',
            'activity_log.view', 'activity_log.view_all',
            'queue.manage',

            /*
             * The audit trail is READ-only, for everybody.
             *
             * There is no `audit.manage` and there must not be: the trail is
             * hash-chained and append-only precisely so that nobody can tidy an
             * inconvenient entry away, and a permission implying otherwise
             * would be a promise the model refuses to keep.
             */
            'audit.view',

            /*
             * Legal holds, the retention log and the GRA approval record.
             *
             * Separated from `settings.manage` because placing a legal hold
             * stops the retention sweep destroying somebody's records, and
             * lifting one lets it resume — decisions with legal weight, not
             * configuration.
             */
            'compliance.view', 'compliance.manage',

            'api_tokens.manage',
            'error_reports.view', 'error_reports.manage',
            'visitor_stats.view',
        ],
    ];

    /**
     * Role → permissions.
     *
     * Three forms, applied in this order:
     *   'group.*'      every permission in that group
     *   'prefix.*'     every permission starting with that prefix
     *   '!name'        REMOVE, applied after all expansion
     *
     * The negation form exists because a group wildcard is convenient but
     * blunt: `fundraising.*` is the right shape for an Admin, yet it sweeps up
     * `payments.view_keys`, which an Admin must not have. Without `!` the
     * choice is between enumerating forty permissions by hand or granting one
     * that a comment claims is withheld — and a comment that disagrees with the
     * code is worse than no comment. A test asserts each `!` actually holds.
     *
     * `*` alone is only ever Super Admin, handled by Gate::before.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLES = [
        'Super Admin' => ['*'],

        'Admin' => [
            'content.*', 'programmes.*', 'fundraising.*', 'shop.*',
            'engagement.*', 'communications.*',
            'admin.access', 'users.view', 'users.create', 'users.update',
            'settings.manage', 'appearance.manage', 'theme.colours.manage',
            'feature_flags.manage', 'maintenance.toggle',
            'backups.run', 'activity_log.view', 'activity_log.view_all',
            'queue.manage',

            // An Admin who can read the live Paystack secret key can move money
            // outside the application entirely, where no audit trail reaches.
            // That stays with Super Admin.
            '!payments.view_keys',

            // `programmes.*` brings the case list (Tier A). It does not bring
            // a child's medical note on every case, the CSV of names, or the
            // views cut for Finance and the Auditor — those are held by the
            // roles that do the work, not by seniority.
            '!beneficiaries.view_sensitive', '!beneficiaries.export',
            '!beneficiaries.view_financial', '!beneficiaries.audit',

            // Not granted at all (absent rather than negated, since no wildcard
            // pulls them in): roles.manage, backups.restore, users.delete. An
            // Admin who can rewrite the permission matrix is a Super Admin with
            // extra steps.
        ],

        'Content Editor' => [
            'admin.access',
            'content.*',
            'projects.view', 'projects.create', 'projects.update',
            'causes.view', 'causes.create', 'causes.update',
            'impact.view',
            'stories.publish', 'consents.view',
            'events.view', 'events.manage',
            'contact.view', 'contact.reply',
            'newsletter.view', 'newsletter.draft',
            'reviews.moderate',
            'templates.email.manage', 'templates.sms.manage',
            'logs.email.view',
            // NOT granted: newsletter.send (a mistake reaches every subscriber
            // at once and cannot be recalled), theme.colours.manage,
            // beneficiaries.*, and anything financial.
        ],

        'Finance Officer' => [
            'admin.access',
            'donations.view', 'donations.view_pii', 'donations.export',
            'donations.record_offline', 'donations.receipt_reissue', 'donations.refund',
            'donors.view', 'donors.update', 'donors.merge',
            'subscriptions.view', 'subscriptions.manage',
            'payments.view_transactions', 'payments.reconcile',
            'causes.view', 'causes.update',
            'impact.view', 'projects.view',
            'orders.view',
            'contact.view', 'contact.reply',
            'logs.email.view', 'logs.sms.view',
            'activity_log.view',
            'pledges.view', 'pledges.manage',
            // Grants and institutional funders (Wave 2).
            'grants.view', 'grants.manage',
            'payouts.view', 'payouts.request', 'payouts.mark_paid',
            // An approved case's name, reference, amount and the account to
            // pay it into — the fields a payout needs. Not the narrative,
            // never the medical note (Wave 2 design note §4).
            'beneficiaries.view', 'beneficiaries.view_financial',
            // NOT granted: donations.refund_over_limit (needs Admin approval),
            // payments.replay_webhook (rewrites financial history),
            // payments.view_keys.
            //
            // And NOT payouts.approve. The officer who prepares a payment is
            // not the person who authorises it — `Payout::approve()` refuses a
            // self-approval, and granting both here would only let somebody
            // discover that on the afternoon they needed the money to move.
        ],

        'Shop Manager' => [
            'admin.access',
            'products.*', 'inventory.manage',
            'orders.view', 'orders.fulfil', 'orders.refund_request',
            'coupons.manage', 'shipping.manage', 'reviews.moderate',
            'media.view', 'media.upload',
            'contact.view', 'contact.reply',
            'templates.email.manage',
            'logs.email.view',
            // Can request a refund; cannot issue one. Fulfilment and money
            // stay separate.
        ],

        'Volunteer Coordinator' => [
            'admin.access',
            'volunteers.*',
            'events.view', 'events.manage', 'events.view_registrations',
            'media.view', 'media.upload',
            'contact.view', 'contact.reply',
            'templates.email.manage', 'templates.sms.manage',
            'logs.email.view', 'logs.sms.view',
            'projects.view', 'impact.view',
        ],

        /*
         * The safeguarding lead. Sees every case in full, decides, records
         * consent, and is the only person who can export the list — the
         * design note's data-protection lead and safeguarding lead are one
         * role until the trustees say otherwise (DESIGN-BENEFICIARY-CASES.md
         * §8 Q2). Not `programmes.*`: this role is about the people, not the
         * projects.
         */
        'Safeguarding Lead' => [
            'admin.access',
            'projects.view', 'causes.view', 'impact.view',
            'beneficiaries.view', 'beneficiaries.manage', 'beneficiaries.view_sensitive',
            'beneficiaries.export',
            'consents.view', 'consents.manage',
            'media.view', 'media.upload',
            'audit.view', 'compliance.view',
        ],

        // Recommended in Blueprint §7.3 and seeded now so the scoping exists
        // from the start rather than being retrofitted.
        'Programme Officer' => [
            'admin.access',
            'projects.view', 'projects.create', 'projects.update',
            'causes.view', 'causes.create', 'causes.update',
            'impact.view', 'impact.manage',
            'beneficiaries.view', 'beneficiaries.manage',
            'consents.view', 'consents.manage',
            'stories.publish',
            'media.view', 'media.upload',
            'documents.manage', 'galleries.manage',
            'grants.view',
        ],

        'Auditor' => [
            'admin.access',
            'donations.view', 'donations.export',
            'donors.view',
            'subscriptions.view',
            'payments.view_transactions', 'payments.reconcile',
            'orders.view',
            'impact.view', 'projects.view', 'causes.view',
            'activity_log.view_all',

            /*
             * The two records an auditor actually comes for: the trail showing
             * who read and exported what, and the compliance record showing the
             * retention policy was followed. Both read-only, like everything
             * else this role holds.
             */
            'audit.view', 'compliance.view',
            // The case process without the person: reference, status, dates,
            // the narrative, the notes, the money — no contact details, no
            // health, no ID (design note §4).
            'beneficiaries.view', 'beneficiaries.audit',
            'grants.view',
            // Read-only by construction. No .create, .update, .delete anywhere.
            // A trustee or external auditor can see the whole financial picture
            // and change none of it.
        ],

        'Support' => [
            'admin.access',
            'contact.view', 'contact.reply',
            'donations.view', 'donations.receipt_reissue',
            'orders.view',
            'volunteers.view',
            // Front desk. Enough to answer a donor; not enough to move money.
        ],

        // Public account. Holds no admin permissions at all — donor capability
        // is enforced by policies scoped to owned records, not by permissions.
        'Donor' => [],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $all = $this->allPermissions();

            foreach ($all as $name) {
                Permission::findOrCreate($name, 'web');
            }

            /*
             * Flush again between creating and assigning.
             *
             * spatie resolves permission names through a cached collection, and
             * normally busts that cache from the Permission model's save events.
             * DatabaseSeeder uses WithoutModelEvents, so those never fire and the
             * registrar keeps the empty snapshot it loaded a moment ago —
             * syncPermissions then throws PermissionDoesNotExist for a permission
             * that was just written.
             *
             * Flushing explicitly is the right fix regardless: cache correctness
             * should not depend on model events being enabled.
             */
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            foreach (self::ROLES as $roleName => $patterns) {
                $role = Role::findOrCreate($roleName, 'web');

                // Super Admin is intentionally granted nothing here. It is
                // handled by Gate::before in AuthServiceProvider, so a
                // permission added next year is covered automatically.
                if ($patterns === ['*']) {
                    continue;
                }

                $role->syncPermissions($this->expand($patterns, $all));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(sprintf(
            'Seeded %d permissions across %d roles.',
            count($this->allPermissions()),
            count(self::ROLES),
        ));
    }

    /** @return array<int, string> */
    private function allPermissions(): array
    {
        return array_merge(...array_values(self::PERMISSIONS));
    }

    /**
     * Expand wildcards, then subtract negations.
     *
     * Negations are applied last and unconditionally, so `'fundraising.*'`
     * followed by `'!payments.view_keys'` reliably withholds that one
     * permission no matter where in the list it appears.
     *
     * @param  array<int, string>  $patterns
     * @param  array<int, string>  $all
     * @return array<int, string>
     */
    private function expand(array $patterns, array $all): array
    {
        $granted = [];
        $denied = [];

        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '!')) {
                $denied[] = substr($pattern, 1);

                continue;
            }

            if (! str_ends_with($pattern, '.*')) {
                $granted[] = $pattern;

                continue;
            }

            $prefix = substr($pattern, 0, -2);

            // `content.*` means the whole content GROUP; `products.*` means
            // every permission starting `products.`. Both forms are useful and
            // both are unambiguous because group names never collide with
            // permission prefixes.
            $granted = array_merge(
                $granted,
                self::PERMISSIONS[$prefix] ?? array_values(array_filter(
                    $all,
                    fn (string $p): bool => str_starts_with($p, $prefix.'.'),
                )),
            );
        }

        return array_values(array_diff(array_unique($granted), $denied));
    }
}
