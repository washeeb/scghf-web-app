# Operations — the calendar, the monitoring, and who to call

What keeps the site healthy after launch. Most of it runs itself from the
scheduler; the rest is a person with a checklist. `TROUBLESHOOTING.md`
when something in here is red.

## 1. What runs by itself

From `routes/console.php`, driven by the one cron line. Nothing here
needs a person unless *Site Health* says so.

| When | What | Notes |
|---|---|---|
| every minute | `scghf:send-messages` — drains the outbox (receipts, confirmations, reminders); the scheduler heartbeat | the worker line drains the queue on the same rhythm |
| every 5 min | `scghf:send-campaigns` — the approved newsletter or SMS broadcast, one batch | throttled by `MAIL_BULK_PER_MINUTE` / `SMS_PER_MINUTE` |
| hourly | `scghf:sweep-shop` (stale unpaid orders release stock), `scghf:contact-sla` (unanswered messages nag), `scghf:payment-anomalies` (failure spikes), `scghf:sms-delivery-reports` | |
| 02:00 daily | `backup:run` — database + media, encrypted, off-server | `BACKUP_*` |
| 03:00 daily | `backup:clean` — prunes by age and size | an hour after, on purpose |
| 04:00 daily | error-report pruning | |
| 05:30 daily | `scghf:verify-audit-log` — the hash chain | quiet when clean; loud when not |
| 06:00 daily | `scghf:charge-recurring --execute` — monthly and weekly gifts due today | dunning emails on failure |
| 06:30 daily | `scghf:reconcile-payments --execute` — asks Paystack about every pending payment in the look-back | *needs review* rows are its output |
| 07:00 daily | `scghf:stock-alerts` | low stock to the shop manager |
| 07:05 daily | `scghf:sms-balance` | emails once a day when under `SMS_LOW_BALANCE_THRESHOLD` |
| 17:00 / 17:10 daily | `scghf:shift-reminders`, `scghf:event-reminders` | tomorrow's shifts and events |
| Monday 07:00 | `scghf:weekly-summary` (to the director), `scghf:retention` (Act 843 sweep, dry run unless executed) | |
| Tuesday 04:30 | `scghf:strip-media-metadata --execute --verify` | catches any file that missed the upload hook |
| 1st of month 04:00 | `scghf:db-maintain --execute` — sessions, old statistics, failed jobs, activity log, `OPTIMIZE TABLE`, largest tables | `PHASE-15-PERFORMANCE.md` §3.5 |
| 2nd of month 04:00 | `scghf:archive-webhook-payloads --execute` | year-old webhook bodies to files |
| 1 February 03:00 | `scghf:archive-audit-log` — the closed year, two years back | dry run; a person runs `--execute` after reading it |

## 2. The maintenance calendar — a person

### Daily (the office, 5 minutes)

- Open the admin. The dashboard card **Site health** is green, or read it.
- *Finance → Donations*: anything **Needs review**? Then the treasurer.
- *Inbox → Messages*: anything unanswered past its SLA badge?
- *Shop → Orders → To fulfil*: pack and dispatch (manual chapter 6).

### Weekly (Monday, 20 minutes)

- Read the **weekly summary** email (giving, orders, applications, what needs attention).
- *Site Health* row by row. Any **warning** gets a date to be fixed by.
- *Communications → Email log* and *SMS log*: bounced or undelivered
  messages — a pattern (all to one domain, all on one network) is a
  deliverability problem, not a typo.
- Refunds awaiting approval; campaigns awaiting approval.
- *System → Failed jobs*: empty, or the developer.

### Monthly (first working day, an hour)

- **Export** last month's donations and orders for the accounts
  (*Finance → Donations → Export*, *Shop → Reports*). Run
  **reconciliation** and clear every *needs review*.
- Read the output of the 1st-of-month maintenance run (the developer,
  from the log): the ten largest tables, anything that failed to prune.
- **Top up SMS credit** if the balance is under a month's use.
- **Check the backup** landed off-server (the backup notification email,
  or the destination itself): the last seven nightly files exist and are
  not zero bytes.
- cPanel → **Disk Usage** and **Statistics → Inodes**: under 70 %.
- **Patch**: `composer outdated` / `npm outdated`; Dependabot PRs merged
  (`PHASE-12-INFRASTRUCTURE.md` §5). Security releases the same week.
- Review the **404 log** (*Website → Redirects & 404s*): add redirects
  for anything that is a real old address.

### Quarterly

- **Restore test**: `php artisan scghf:restore-test` against the scratch
  database — *Site Health* warns after `restore_test_interval_days` (90).
  A backup that has not been restored is a hope, not a backup.
- **Access review**: *System → Staff accounts* — everyone still works
  here; roles still match jobs; leavers suspended.
- **Sender ID and SPF/DKIM** still valid (a DNS change elsewhere can
  break both): send a test from *Email templates* and read the headers.
- **Lighthouse** on staging after any design change
  (`PHASE-15-PERFORMANCE.md` §4.3).
- Run the **UAT checklist** (`PHASE-14-QA.md` §4) on staging after any
  release that touched money or forms.

### Annually

- **Archive the audit log**: read the 1 February dry run, then
  `scghf:archive-audit-log --execute`.
- **Retention**: read the Monday dry-run output for the year; execute
  with the data-protection lead present.
- **Rotate**: the Paystack webhook secret (from the dashboard), the mail
  and SMS API keys, the deploy SSH key. `SECURITY-MODEL.md` §5 step 4.
- **Renew**: the domain, the SSL certificate (AutoSSL should; check),
  the hosting plan, the backup destination's billing.
- **DPC registration** renewal and the privacy notice review
  (`PHASE-12-DATA-PROTECTION.md`).
- **Paystack**: confirm the settlement account and the fee schedule;
  update `PAYSTACK_FEE_*` if the rate card changed.
- Re-run the payments test plan §3 (one live cedi) after any Paystack
  account change.

## 3. Monitoring checklist

| Signal | Where | Who sees it | Set up by |
|---|---|---|---|
| Site up | UptimeRobot on `/up` (and a keyword check on `/donate`) | alerts address + a phone | developer; `PHASE-12-INFRASTRUCTURE.md` §3 |
| Application errors | Sentry (`SENTRY_LARAVEL_DSN`) | developer | developer |
| Scheduler and queue alive | *Site Health* heartbeat rows; the weekly summary stops arriving | office | built in |
| Backup ran | the backup notification email; *Site Health → Backups* | office + developer | `BACKUP_NOTIFICATION_EMAIL` |
| Payment anomalies | the anomaly email (`PAYMENT_ALERT_*`) | treasurer | built in |
| Large gift | the alert email (*Settings → Email & SMS → tell me about a gift of at least*) | director | setting |
| SMS credit low | daily email under the threshold | office | `SMS_LOW_BALANCE_THRESHOLD` |
| Inodes / disk | *Site Health → File count*; cPanel Statistics | office monthly | `MEDIA_INODE_BUDGET` |
| Audit chain broken | `scghf:verify-audit-log` emails when not clean | developer + treasurer | built in |
| CSP violations | `csp-report` log entries | developer, on request | built in |

**The alerts address** is *Settings → Email & SMS → alerts email* (falls
back to the general contact address). It should be a real inbox that a
person reads every working day — not a group nobody owns.

## 4. Escalation contacts

The names and numbers live in the office copy of this page and on the
manual's quick-reference card, not in the repository. The roles:

| Role | Responsible for | Reach when |
|---|---|---|
| **Site owner** (the director) | decisions: publish, refund policy, spend | anything that needs a decision |
| **Treasurer / finance lead** | Finance: reconciliation, refunds, reports, the Paystack account | *needs review*, a refund request, a mismatch |
| **Developer / maintainer** | the code, deploys, the server account, Sentry, this documentation | red on Site Health, an error page, a deploy, a missing payment after chapter 5 of the manual |
| **Safeguarding lead** | photographs of children, consent, concerns raised through the site | a consent question, a *Raise a concern* submission |
| **Data-protection lead** | Act 843: subject requests, retention execution, breaches | an export/delete request the site could not fulfil itself; any breach |
| **Hosting (InMotion) support** | the account, PHP versions, cron, quotas | via the developer |
| **Paystack support** | settlements, disputes, the dashboard | via the treasurer or developer |

Escalation order for a **money** problem: office → treasurer → developer
→ Paystack. For a **site down** problem: office → developer → hosting.
For a **safeguarding or data** problem: office → the relevant lead →
director; and the incident runbook (`SECURITY-MODEL.md` §5).

## 5. Handover checklist (when the maintainer changes)

- [ ] GitHub: the new person is an owner of the repository; the old
      deploy key is replaced (`PHASE-2-RUNBOOK.md` step 5) and the
      `SSH_PRIVATE_KEY` secret rotated.
- [ ] cPanel: a separate login for the new person; the old one removed.
- [ ] Sentry, UptimeRobot, Resend, the SMS provider, the backup
      destination: access transferred, not shared.
- [ ] `shared/.env` on each server read once, together, against
      `ENVIRONMENT.md`.
- [ ] A staging deploy and a production deploy done by the new person
      while the old one watches (`DEPLOYMENT.md`).
- [ ] A restore test run by the new person.
- [ ] This page and the manual's quick-reference card updated.
