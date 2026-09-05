# Phase 3 — Data Architecture

**The schema, designed before it is written.** ~150 tables across eight modules. This document is the thing to argue with; migrations follow it, not the other way round.

| | |
|---|---|
| Engine | MySQL 8.4 / MariaDB 11+ · InnoDB · `utf8mb4` / `utf8mb4_unicode_ci` |
| Money | integer pesewas in `BIGINT UNSIGNED`, never a float, never `DECIMAL` |
| Public IDs | ULID — sortable, non-enumerable, safe in a URL |
| Status | Design agreed → migrations written module by module |

---

## 0. Sequencing

150 tables is not one commit. Migrations land in dependency order, each module complete with its models, factories and seeders before the next starts:

| # | Module | Tables | Depends on |
|---|---|---|---|
| 1 | **Core & Auth** | ~12 | — |
| 2 | **Settings & CMS** | ~28 | Core |
| 3 | **Programmes** | ~18 | Core, CMS |
| 4 | **Fundraising** | ~24 | Core, Programmes |
| 5 | **Shop** | ~26 | Core, Fundraising *(shared payment tables)* |
| 6 | **Engagement** | ~22 | Core, Programmes |
| 7 | **Communications** | ~10 | Core |
| 8 | **System** | ~8 | Core |

**Fundraising before Shop is deliberate.** Both take payments, and `payment_transactions` / `payment_webhook_events` are shared polymorphically. Building Shop first would produce a second payment path — which `CLAUDE.md` explicitly forbids and which is how ledgers diverge.

---

## 1. Conventions

Applied everywhere, no exceptions. Where a table deviates, the reason is stated on the table.

### 1.1 Keys and identifiers

Every table carries **three** identity concepts, and confusing them is a real source of bugs:

| Concept | Column | Type | Purpose |
|---|---|---|---|
| Internal key | `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Joins and foreign keys only. Fast, compact, never leaves the server. |
| Public identifier | `ulid` | `CHAR(26)` unique | Anything in a URL, an API response, or an email. Non-enumerable. |
| Human reference | `reference` | `VARCHAR(32)` unique | Only on donations, orders, receipts. Quotable over the phone. |

**Why not UUID primary keys?** A random UUID as a clustered InnoDB primary key fragments the B-tree and inflates every secondary index — a real cost on shared hosting. Auto-increment internally plus a ULID for external exposure gets both properties.

**Why ULID over UUIDv4?** Lexicographically sortable by creation time, so `ORDER BY ulid` is meaningful and the index stays dense.

**Never expose `id` in a URL.** `/donations/1` tells an attacker there is a donation 2, and roughly how many donations exist. Route-model binding uses `ulid`.

### 1.2 Money

```
amount_minor      BIGINT UNSIGNED NOT NULL     -- pesewas. 5000 = GH₵ 50.00
currency          CHAR(3) NOT NULL DEFAULT 'GHS'
```

- **`BIGINT UNSIGNED`, not `DECIMAL`, not `FLOAT`.** `DECIMAL` is exact but invites arithmetic in SQL and float casts in PHP. Integers make the wrong thing hard.
- **Unsigned**, because a negative donation is a bug, not a value. Refunds are their own rows in `refunds`, not negative donations — a negative amount in a ledger destroys the ability to sum it meaningfully.
- Every amount column is suffixed `_minor` so nothing reads like a decimal.
- `currency` sits beside the amount even though it is always `GHS`. Amount without currency is meaningless data, and Paystack rejects a call missing it.
- Read and written through `App\Casts\MoneyCast` → `App\ValueObjects\Money`. Already built and tested (53 tests).

### 1.3 References

Immutable, unique, human-readable, generated once and never changed:

```
SCGHF-D-7F3K9M2Q     donation
SCGHF-O-4B8XP1RT     shop order
SCGHF-R-2026-000148  receipt (sequential per year — a finance requirement)
SCGHF-S-9WQ4NC7L     subscription
```

Crockford base32 from the ULID, so it is unambiguous read aloud — no `I`/`1` or `O`/`0` confusion, which matters when a donor phones about a gift.

**Receipts are the exception: strictly sequential per financial year, with no gaps.** Auditors expect that; a gap has to be explainable. Sequence allocation happens inside the transaction that creates the receipt.

### 1.4 Timestamps and audit

| Column | On | Notes |
|---|---|---|
| `created_at` / `updated_at` | everything | |
| `deleted_at` | content tables | soft delete |
| `created_by` / `updated_by` | admin-editable tables | `users.id`, `ON DELETE SET NULL` |
| `deleted_by` | anything soft-deletable by an admin | who removed it |

Nullable throughout: seeded rows and system-generated records have no author.

`ON DELETE SET NULL` rather than `CASCADE` — a departing staff member must not take their content with them.

### 1.5 Soft deletes

**Content tables: yes.** Pages, posts, projects, products, media.

**Financial records: no.** `donations`, `payment_transactions`, `payment_webhook_events`, `refunds`, `order_items`, `receipts` are **append-only**. A completed donation is never deleted or edited — corrections are new rows. This is what makes the ledger auditable, and it is the difference between a system a trustee can sign off and one they cannot.

**Beneficiary records: soft delete, plus a documented hard-delete path** for Act 843 erasure requests. Soft delete satisfies day-to-day operations; a genuine right-to-erasure request needs real removal, and that path is deliberate and logged.

### 1.6 Indexes

- Every foreign key. MySQL requires one anyway; being explicit keeps it visible.
- Every column used in a `WHERE`, `ORDER BY`, or `GROUP BY` on a list screen.
- Composite indexes ordered by selectivity, e.g. `(status, created_at)` for the donations list.
- Unique on `ulid`, `reference`, `slug`, and every natural key.
- `slug` unique **per parent** where scoped — `UNIQUE(project_id, slug)`.

⚠️ **The `utf8mb4` index length trap.** In `utf8mb4`, one character can be 4 bytes, and InnoDB's index limit is 3072 bytes — so an indexed `VARCHAR(255)` consumes 1020 bytes and **three of them in one composite index will not build.** Every indexed string column is therefore sized to what it actually needs:

| Column kind | Length | Rationale |
|---|---|---|
| `slug` | 191 | historically safe, still sensible |
| `email` | 191 | RFC allows 254, but no real address needs an index over 191 |
| `reference` | 32 | generated, fixed shape |
| `ulid` | 26 | fixed |
| `status`, `type` | 32 | short tokens |
| unindexed prose | `TEXT` | never indexed, so length is free |

**This is a MySQL constraint SQLite does not enforce** — which is precisely why local development moves to MySQL before these migrations are written (runbook step 2.5).

### 1.7 Enumerated values

Stored as `VARCHAR(32)`, backed by a **PHP enum**, never a MySQL `ENUM`.

Changing a MySQL `ENUM` is an `ALTER TABLE` that rewrites the table — on a live donations table on shared hosting, that is an outage. Adding a case to a PHP enum is a deploy. The database keeps a `CHECK` constraint where the engine supports it, and the application is the source of truth.

### 1.8 Naming

Tables plural snake_case (`donation_items`). Foreign keys singular + `_id` (`donation_id`). Booleans `is_`/`has_`/`can_`. Timestamps `_at`. Money `_minor`. Counts `_count`. Polymorphic pairs `{name}_type` + `{name}_id`.

---

## 2. Module ERDs

Cardinality notation: `1—*` one-to-many · `*—*` many-to-many · `1—1` one-to-one · `◇` polymorphic.

### 2.1 Core & Auth

```
users 1—* login_histories
users 1—* notifications
users *—* roles            (model_has_roles)
roles *—* permissions      (role_has_permissions)
users *—* permissions      (model_has_permissions, direct grants)
users 1—* admin_activity_log     (as causer)
users 1—1 donors                 (nullable both ways — a donor may be a guest)
```

| Table | Purpose |
|---|---|
| `users` | Everyone who can log in — staff and donors alike. `type` distinguishes them; roles carry capability. |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | `spatie/laravel-permission`. Blueprint §7 matrix. |
| `sessions`, `password_reset_tokens` | Laravel defaults, `database` session driver. |
| `login_histories` | IP, UA, outcome, timestamp. Feeds the new-device alert. |
| `admin_activity_log` | `spatie/laravel-activitylog`. Field-level admin audit. |
| `notifications` | Laravel's polymorphic notifications table. |
| `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks` | Framework infrastructure, `database` drivers. |
| `media` | `spatie/laravel-medialibrary`, polymorphic ◇ across every content type. |

**`users.type`** — `staff` or `donor`. A donor account carries no admin capability; a staff account is never a donation target. Keeping them in one table means one auth path, one 2FA implementation, one password policy.

**2FA columns** live on `users`: `two_factor_secret`, `two_factor_recovery_codes` (both encrypted), `two_factor_confirmed_at`. Mandatory for every staff role, optional for donors.

### 2.2 Settings & CMS

```
settings                      (grouped key/value, typed)
theme_settings                (light + dark tokens, from Blueprint §5)
pages 1—* page_sections       (ordered flexible blocks)
pages 1—* page_revisions
page_sections *—1 block_types
menus 1—* menu_items          (self-referencing tree via parent_id)
media_folders 1—* media_folders (tree)
posts *—1 blog_categories · posts *—* tags · posts 1—* comments
faqs *—1 faq_categories
team_members *—1 team_departments
galleries 1—* gallery_items
seo_meta ◇ (any model)
redirects
```

| Table | Purpose |
|---|---|
| `settings` | Grouped, typed key/value. Every operational value the CMS rule demands: contact block, socials, donation presets, legal-page mapping. Cached; cache busted on write. |
| `theme_settings` | The Blueprint §5 tokens, light and dark, editable with AA validation. |
| `pages`, `page_sections`, `block_types`, `page_revisions` | The page builder. `page_sections.data` is `JSON` — the one justified JSON column, because block payloads genuinely vary by type. Everything else gets a real table. |
| `menus`, `menu_items` | Nested drag-and-drop. `menu_items` is polymorphic ◇ — an item points at a page, project, cause, product, or an external URL. |
| `banners`, `announcements`, `popups` | Time-boxed promotional content with `starts_at`/`ends_at`. |
| `faqs`, `faq_categories`, `testimonials`, `partners`, `team_members`, `team_departments`, `galleries`, `gallery_items`, `documents` | Standard CMS content. |
| `seo_meta` | Polymorphic ◇. Title, description, OG image, canonical, `noindex`. |
| `redirects` | Manual plus the 404→redirect workflow. Indexed on `from_path`. |
| `contact_messages`, `contact_departments` | Enquiry inbox with routing. |
| `subscribers` | Newsletter, with the consent record inline. |
| `translations`, `locales` | Scaffolding only — English at launch. |

### 2.3 Programmes

```
divisions 1—* focus_areas
divisions 1—* projects · divisions 1—* causes · divisions 1—* impact_metrics
projects 1—* project_updates · project_milestones · project_documents
projects *—* project_locations · projects *—* partners
projects 1—* beneficiaries (restricted)
beneficiaries 1—* consents          ← gates publication
impact_metrics 1—* impact_metric_values   (time series)
stories *—1 beneficiaries  (consent-gated)
```

**`divisions` is the spine.** The four divisions from the profile — Life Spring, BrightPath, Legacy of Love, Every Soul Missions. Seeded, undeletable, and referenced by nearly every content and money table via a nullable `division_id` (null = foundation-wide).

**`consents` is the table that keeps the foundation out of trouble.** Polymorphic ◇ over beneficiaries and stories:

```
consent_type      photo | story | video | name_use
granted_by        who signed — the beneficiary or a guardian
guardian_name     required when the subject is a minor
scope             website | print | social | all
granted_at, expires_at, revoked_at
evidence_media_id the signed form
```

**A beneficiary image or story cannot be published without a matching unexpired, unrevoked consent row.** Enforced in the model layer and asserted by tests — not left to an admin remembering.

### 2.4 Fundraising

The module where correctness matters most. Full column detail in §3.

```
causes 1—* donations
divisions 1—* donations
donors 1—* donations
donation_plans 1—* subscriptions 1—* subscription_charges
subscriptions 1—* donations                  (each cycle's charge)
donations 1—* donation_items                 (split/designated giving)
donations 1—1 donation_receipts
donations ◇—1 payment_transactions           (payable)
orders    ◇—1 payment_transactions           (payable)
payment_transactions 1—* payment_webhook_events
payment_transactions 1—* refunds
fundraisers 1—* donations                    (peer-to-peer, flagged off)
pledges, offline_donations, payouts
```

**One payment path for both donations and shop orders.** `payment_transactions.payable_type` + `payable_id`. One `PaystackService`, one webhook handler, one reconciliation job. Two payment paths is how a ledger diverges from the gateway.

**`donation_items` exists even for single-designation gifts.** A GH₵ 100 donation split across three divisions is three rows summing exactly to the parent — using `Money::allocate()`, which is already tested to reconcile. Uniform structure beats a nullable special case.

### 2.5 Shop

```
product_categories 1—* products (tree via parent_id)
products 1—* product_variants 1—* inventory_movements
products 1—* product_images · products 1—* product_reviews
carts 1—* cart_items
orders 1—* order_items · orders 1—* order_status_histories
orders 1—1 invoices · orders 1—* digital_download_tokens
shipping_zones 1—* shipping_rates            (Ghana's 16 regions)
coupons 1—* coupon_redemptions
```

**`order_items` snapshots name, SKU and unit price at the time of order.** Never joined to the live product for price. A product's price changing must not retroactively alter what a customer paid — that is an accounting error and a trust problem.

**`inventory_movements` is an append-only ledger, not a mutable `stock` integer.** Every change is a row with a reason (`sale`, `restock`, `adjustment`, `return`, `hold`, `hold_release`). Current stock is the sum. A bare counter silently loses history the first time two orders race.

### 2.6 Engagement

```
volunteer_opportunities 1—* volunteer_applications
volunteers 1—* volunteer_hours
events 1—* event_registrations · events 1—* event_tickets
newsletters 1—* newsletter_campaigns 1—* campaign_recipients
prayer_requests
```

`volunteer_applications` carries the safeguarding declaration and CV reference. CVs are stored **outside the web root** and served through a signed, authorised route — never a public URL.

### 2.7 Communications — built 2026-09-03

`email_templates`, `sms_templates`, `suppressions`, `email_logs`, `sms_logs`, `notification_logs`, `scheduled_messages`, `newsletters`, `newsletter_campaigns`, `campaign_recipients`.

Ten tables rather than seven: the three newsletter tables were deferred out of Module 6 because a campaign needs templates to render from and a suppression list to check against.

```
email_templates 1—* email_logs · sms_templates 1—* sms_logs
suppressions (one row per address per channel)
scheduled_messages (the outbox)
newsletters 1—* newsletter_campaigns 1—* campaign_recipients —1 email_logs
```

**`suppressions` is not optional.** Hard bounces and complaints land here and every send checks it. Without it the list rots and the sending domain's reputation goes with it — Blueprint risk DEL-4.

**Suppression has a scope, and it is the whole design.** `all` stops everything including receipts; `marketing` stops appeals only. An unsubscribe is `marketing` — somebody who no longer wants appeals has not asked to stop receiving the record of a gift they just made. A bounce is `all`. Getting this backwards fails in opposite directions: one blocklists the domain, the other quietly withholds somebody's only evidence of a donation.

Suppression only ever *strengthens* automatically. Coming off the list needs a named person and a recorded reason, and an address suppressed under an Act 843 objection cannot be released at all — honouring the objection is what the row is for. The list is deliberately **excluded from the retention sweep**: forgetting that somebody objected is how they start receiving mail again after asking not to.

**A refusal is an outcome, not an absence.** Every attempt is logged including the ones that never left — suppressed, disabled, expired, failed. A receipt blocked by the list produces a row explaining itself, so Finance can post it or hand it over. Silent non-delivery of a receipt is the failure this module exists to make impossible.

**One door out.** Nothing sends any other way; campaign mail included, which renders through the seeded `newsletter.campaign` template. A second path would be a path with no suppression check on it, and bulk is where that matters most.

`sms_logs` records segment count, encoding, network and estimated cost per message, so the `SMS_DRIVER=log` mode is genuinely informative before a provider exists — a month of running the site produces a real estimate of what SMS will cost.

**The throttle is a `COUNT`, not a counter.** cPanel caps outbound mail per hour and the queue runs from cron in fifty-five-second bursts, so every minute is a fresh process and workers are routinely killed mid-batch. The rate limiter counts rows in `email_logs` with `sent_at` inside the window: atomic without a lock, self-correcting after a crash, and incapable of disagreeing with what was actually sent. Hence the index on `(sent_at)`.

**Consent is re-checked per message, not per campaign.** At two hundred messages an hour a campaign to two thousand people takes most of a day, so hours pass between building the list and sending the last of it. `campaign_recipients` is a build list and a claim queue; the permission is re-read at the moment each message goes.

**`scheduled_messages` carries an expiry.** A backlog here is measured in days, and a queue that eventually catches up and delivers "the event is tomorrow" three days late is worse than one that delivers nothing and records why. Receipts have no expiry.

Two gates before a campaign can go, both defaults in `config/communications.php`: a **test send** must have happened, and an **approval** must be recorded by somebody holding `newsletter.send`. Editing the content afterwards withdraws both — what was approved is no longer what would go.

**Open/click tracking is off.** Recording that a named person read a message, when, is Act 843 processing needing its own lawful basis and its own line in the privacy notice. The columns exist so enabling it is a config change, not a migration.

### 2.8 System — built 2026-09-04

`audit_logs`, `api_tokens`, `error_reports`, `backups_log`, `visitor_stats`, `feature_flags`, `settings_history`.

**`audit_logs` records actions; `activity_log` records changes.** spatie/laravel-activitylog is already installed and already captures model writes. This captures the events that change nothing — opening a beneficiary's file and reading their medical history, exporting four thousand donor records, running the retention sweep, releasing a suppression. None of those touch a model, so until now none of them left a trace anywhere. For a foundation holding files on vulnerable children, *who read this?* is the more serious question.

Events are declared in `config/system.php` with a category and a severity, and recording an undeclared one throws — a new export screen has to be classified before it can log, rather than filing itself under "unknown" and disappearing from every report. **Volume escalates severity on its own:** one donor record viewed is somebody doing their job; two thousand exported is a question that needs asking the same day.

The trail is append-only and **chained** — each row hashes its content together with the previous row's hash, so an edit or a deletion breaks every hash after it and `scghf:verify-audit-log` names the first break. What that buys is stated precisely because it is easy to overclaim: tampering becomes **detectable, not impossible**. Somebody with database access can recompute the chain from the tampered point onward. The control that defeats *that* is anchoring the head hash outside the database, which the verify command writes to the application log on every clean run; an auditor comparing today's head against last month's anchored value is the actual control.

Auditing never breaks the action it audits — a failed write is logged off-database and the caller proceeds. An audit trail that can take down a donation form is removed within a week, and then there is no trail at all.

**`api_tokens`** are hashed and shown once, grant nothing by default, and carry a mandatory expiry. Beneficiary, safeguarding, donor and refund abilities can never be granted: those are decisions a person makes while logged in, not something an integration does unattended.

**`error_reports` are grouped, not accumulated.** Ten thousand copies of one fault is one problem, and on a shared plan that counts inodes it is also a full disk. Keyed by a fingerprint of class, file, line and the message with its variable parts normalised, so a failing page does not produce a row per visitor. Nothing from the request body is ever captured — PCI DSS SAQ-A posture rests on this application never touching card data, and a reporter that grabbed the POST body would quietly make that untrue.

**A backup that has never been restored is a hypothesis.** `backups_log` therefore records **restore tests** as first-class rows: one with no row count verified nothing, one with no named verifier is an assertion rather than evidence, and both are refused. `healthWarnings()` says *"no backup has ever been restored and verified"* — the state every project is in until somebody does it, and the state most stay in. `file_count` sits beside `size_bytes` because shared hosting counts inodes, and a media library plus a month of archives hits that limit long before it runs out of disk.

**`visitor_stats` is aggregate by construction** — no IP, no fingerprint, no cross-site identifier and no per-visitor row, not as a policy somebody could relax but because the schema has nowhere to put one. A test asserts the exact column list, so adding one has to be argued for. The cost, stated because somebody will ask: **this application cannot report unique visitors.** It reports views, and sessions counted from the session it already sets for its own reasons. The one figure that changes engineering decisions is the mobile share, which is what justifies the 3G performance budget.

**`feature_flags` can toggle a flag; they cannot invent one.** `config/features.php` stays the source of truth for which flags exist, because flags are reviewed like code and their history is in git. The database adds what config cannot — turning something off on a Saturday evening without a deployment — with a required reason and an **expiry**, the column that stops a temporary measure becoming permanent. A lapsed override is simply not loaded, so nothing has to run to expire one. `donations` is locked against admin-panel override entirely. When the table cannot be read, the answer falls back to config: during an outage the honest answer is whatever was deployed.

**`settings_history`** exists because settings hold the GRA approval reference, the receipt signatory, the legal name and the TIN — values that appear on documents going to a regulator. The hook lives on the `Setting` model, not in Filament, so it holds however the value is changed. Encrypted settings record *that* they changed and redact both values: a history table is the last place a plaintext secret should accumulate. No foreign key to `settings`, so *"what did that used to be, before we deleted it?"* stays answerable.

---

## 3. The tables where money lives

Full detail, because these are the ones that must be right.

### 3.1 `donations` — append-only

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `ulid` | CHAR(26) UNIQUE | public identifier |
| `reference` | VARCHAR(32) UNIQUE | `SCGHF-D-…`, immutable |
| `donor_id` | FK→donors NULL | null for a guest gift |
| `user_id` | FK→users NULL | set if logged in |
| `cause_id` | FK→causes **NOT NULL** | General Fund is the seeded fallback |
| `division_id` | FK→divisions NULL | denormalised from cause for reporting |
| `subscription_id` | FK→subscriptions NULL | set on recurring cycles |
| `fundraiser_id` | FK→fundraisers NULL | peer-to-peer |
| `amount_minor` | BIGINT UNSIGNED | what the donor gave |
| `fee_minor` | BIGINT UNSIGNED DEFAULT 0 | Paystack's fee |
| `fee_covered_by_donor` | BOOLEAN DEFAULT false | |
| `net_minor` | BIGINT UNSIGNED | what actually reaches the foundation |
| `currency` | CHAR(3) DEFAULT 'GHS' | |
| `status` | VARCHAR(32) | `pending` `completed` `failed` `abandoned` `needs_review` `refunded` |
| `channel` | VARCHAR(32) NULL | `mobile_money` `card` `bank` `ussd` `offline` |
| `momo_network` | VARCHAR(32) NULL | MTN / Telecel / AT |
| `is_anonymous` | BOOLEAN DEFAULT false | hides from the donor wall, not from Finance |
| `tribute_type` | VARCHAR(32) NULL | `in_memory_of` / `in_honour_of` |
| `tribute_name`, `tribute_message`, `tribute_notify_email` | | the memorial feature |
| `donor_name`, `donor_email`, `donor_phone` | VARCHAR | snapshot at time of gift |
| `consent_email`, `consent_sms` | BOOLEAN | captured per channel |
| `consent_text`, `consent_ip`, `consent_at` | | Act 843 evidence |
| `paid_at`, `failed_at` | TIMESTAMP NULL | |
| `paystack_reference` | VARCHAR(191) NULL, indexed | gateway's own id |
| `notes` | TEXT NULL | Finance annotations |

**Indexes:** `(status, created_at)` · `(cause_id, status)` · `(division_id, created_at)` · `(donor_email, created_at)` · unique `ulid`, `reference` · index `paystack_reference`, `subscription_id`.

**No `deleted_at`.** Append-only.

`net_minor` is stored, not computed on read — the fee is what Paystack actually charged, which can differ from our model. Reconciliation compares the two.

### 3.2 `payment_transactions` — the gateway boundary

| Column | Notes |
|---|---|
| `ulid` | public identifier |
| `payable_type` / `payable_id` | ◇ donation or order |
| `gateway` | `paystack` \| `fake` (dev) \| `offline` |
| `gateway_reference` | UNIQUE — the idempotency anchor |
| `amount_minor`, `currency` | **what we expected** |
| `amount_paid_minor`, `currency_paid` | **what actually happened** |
| `status` | `initialised` `pending` `success` `failed` `abandoned` `mismatch` |
| `channel`, `authorization_code`, `card_last4`, `card_brand` | never full card data |
| `initialised_at`, `paid_at`, `verified_at`, `reconciled_at` | |
| `request_payload`, `response_payload` | JSON, **scrubbed** of card/cvv/pin/auth |

Storing expected *and* actual separately is what makes the amount/currency mismatch check possible. A mismatch sets `status = mismatch`, flags Finance, and **never** auto-completes the donation — Blueprint risk PAY-5.

### 3.3 `payment_webhook_events` — replay-proof

| Column | Notes |
|---|---|
| `event_id` | **UNIQUE** — the whole idempotency guarantee |
| `event_type` | `charge.success`, `subscription.create`, … |
| `gateway_reference` | indexed, links to the transaction |
| `raw_payload` | LONGTEXT, stored **before** any parsing |
| `signature`, `signature_valid` | audit of the HMAC check |
| `received_at`, `processed_at`, `processing_error` | |
| `attempts` | retry count |

The unique index on `event_id` is what makes replay a no-op at the database level rather than relying on application logic getting it right — Blueprint risk PAY-4.

`raw_payload` is written before parsing so a malformed webhook is still evidence.

---

## 4. Shared-hosting constraints, applied

| Constraint | How the schema respects it |
|---|---|
| InnoDB row limit ~8KB | Long prose in `TEXT`, which stores off-page. No table with many long `VARCHAR`s. |
| Index length 3072 bytes | §1.6 column sizing. Composite indexes counted before they are written. |
| No Redis | `cache`, `sessions`, `jobs`, `job_batches`, `failed_jobs` all real tables, all indexed and pruned on a schedule. |
| Inode budget | Media conversions capped at 3 per upload. `media` rows are cheap; files are not. |
| Modest CPU | No unbounded admin queries. Every list screen paginates. Counters denormalised where a `COUNT(*)` would run per row. |
| Backups matter | Append-only financial tables mean a restore is coherent even if it loses recent rows. |

**Denormalised counters** — `causes.raised_minor`, `causes.donation_count`, `projects.beneficiary_count` — are maintained inside the same transaction as the donation, incremented atomically (`UPDATE … SET raised_minor = raised_minor + ?`), never by reading then writing. A read-modify-write loses money under concurrency, and two donations landing in the same second is exactly when it matters.

---

## 5. Migration safety policy

**Binding from here on.**

1. **Never `migrate:fresh` or `migrate:refresh` outside local.** Not on staging either — staging holds test data that took effort to create.
2. **Always `migrate --force` in CI/production**, non-interactive.
3. **Every migration is reversible.** `down()` is written and tested, not left as a stub.
4. **Expand-only within a deploy.** Migrations run *before* the release symlink flips, so the previous release serves traffic against the new schema for a few seconds. Therefore:
   - ✅ Add a table, add a nullable column, add an index, add a value to a PHP enum
   - ❌ Drop a column, rename a column, narrow a type, add a `NOT NULL` without a default
5. **A rename is three deploys.** Add the new column → backfill and dual-write → drop the old one once nothing references it.
6. **Backfills are chunked jobs, not migrations.** A migration updating 50,000 rows will exceed the cron worker's 55-second budget and be killed mid-write.
7. **Pre-deploy dump before any migration touching a financial table.** Automated in the deploy pipeline once hosting exists.
8. **Every migration runs against a staging clone of production data before production.** Schema that works on an empty table can fail on real data — duplicate values that block a unique index, a column too narrow for what is already there.

> **Migrations do not roll back with the code.** `rollback.sh` moves the symlink; the schema stays. That asymmetry is the reason rules 4 and 5 exist and are not negotiable.

---

## 6. Database provisioning

Hosting is undecided, so this is host-agnostic with cPanel notes where they differ.

**Any host:**

```sql
CREATE DATABASE scghf_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'scghf'@'localhost' IDENTIFIED BY '<strong password>';
GRANT ALL PRIVILEGES ON scghf_prod.* TO 'scghf'@'localhost';
FLUSH PRIVILEGES;
```

**On cPanel**, the wizard does this and prefixes everything with the account username (`user_scghf_prod`). Grant ALL PRIVILEGES; Laravel migrations need `CREATE`, `ALTER`, `INDEX` and `REFERENCES`, not just CRUD.

`DB_HOST` is `localhost` on cPanel (socket), `127.0.0.1` locally (TCP). They are not interchangeable — `localhost` uses a Unix socket that will not exist on your workstation.

**Never** `mysqldump` production to a location inside the web root. Backups go off-server, encrypted — Blueprint OPS-6.

---

## 7. Open questions

### ✅ Answered 2026-09-02

**1. Financial year starts 1 January.** Receipt numbers restart at 1 each calendar year: `SCGHF-R-2026-000148`. Sequence allocation happens inside the transaction that creates the receipt, so there are no gaps — an auditor expects to be able to account for every number.

**3. Donations are BOTH tax-deductible and non-deductible**, depending on what they fund. This is more than a wording change and it propagates:

- **Deductibility is a property of the destination, not the foundation.** `causes.is_tax_deductible` carries the rule; `donation_items.is_tax_deductible` **snapshots it at the time of the gift**. If the status of a cause changes later, receipts already issued must not silently change meaning — the snapshot is what makes an old receipt still true.
- **A single donation can be mixed.** Split or designated giving means one GH₵ 500 gift may be GH₵ 300 deductible and GH₵ 200 not. That is precisely why `donation_items` exists for every donation rather than only for split ones (§2.4) — the deductible subtotal is a sum over items, not a flag on the parent.
- **`donations` therefore carries `deductible_amount_minor`**, denormalised from its items inside the same transaction, so receipts and year-end statements do not have to recompute it and cannot disagree with what was printed.
- **Receipts show a breakdown**, not a single total: deductible subtotal, non-deductible subtotal, gross. `donation_receipts` gains `deductible_amount_minor` and a `tax_statement` text snapshot, because the prescribed wording can change between years and an old receipt must keep the wording it was issued with.
- **`{{TIN}}` becomes required** on any receipt claiming deductibility, which promotes it from a §0.1 placeholder to a launch blocker for the donation flow.

> ✅ **Answered 2026-09-03.** The GRA prescribes no mandatory receipt wording, so the question was the wrong shape. See *Acknowledgements* below.

### Acknowledgements — corrected 2026-09-03

The document is an **ACKNOWLEDGEMENT OF CONTRIBUTION/DONATION TO A WORTHWHILE CAUSE**, not a "tax-deductible receipt". Under Act 896 s.100 the donor claims the deduction on their own return, supported by a written acknowledgement from a verifiable beneficiary; for the charitable-organisation route the recipient must hold an unexpired written approval issued by the Commissioner-General under s.97. The Foundation acknowledges the gift; the GRA decides the deduction. A document titled "tax-deductible receipt" asserts the second thing, which is not the Foundation's to assert.

This **corrects the note above**: `causes.is_tax_deductible` is necessary but **not sufficient**. Deductibility wording also requires a current written GRA approval, held in `tax_approvals` and evaluated from its dates on every call. With no approval on file every deductibility claim is suppressed, whatever any cause or CMS setting says.

Three paragraphs, in `config/compliance.php`:

1. **s.97 status** — rendered only while a valid Notice of Approval is held *on the date of the donation*
2. **the acknowledgement of receipt** — always, with the amount in figures and in words
3. **s.100 purpose and the GRA-determination disclaimer** — mandatory alongside paragraph 1, never omitted

Validity is tested against the **donation date**, not today. An acknowledgement reprinted after an approval lapses still cites what was true when the gift was received; a gift received before the Foundation was approved never acquires that approval retroactively; a revoked approval is never cited at all.

A donation received while no approval was held still produces a document — a plain receipt with no tax wording anywhere in it. The donor is entitled to evidence of their gift; what they are not entitled to is wording the Foundation cannot support.

`{{TIN}}` is a hard blocker: `Settings` reports an unfilled placeholder as absent, and acknowledgements **refuse to issue** rather than printing a blank line on a document destined for a tax authority.

### Answered 2026-09-03

**2. Beneficiary data retention — purpose-based, per Act 843.** Declined applications 24 months from decision; incomplete or withdrawn 12 months from last activity; approved case records 6 years from closure, then de-identified rather than deleted so the financial trail survives while the person does not; medical and other highly sensitive supporting documents 24 months from closure — deliberately far shorter than the case record they support, because a report proving eligibility has served its purpose once the case closes; tax and accounting records a 6-year statutory MINIMUM and never auto-deleted; anonymised statistics indefinitely. Legal, audit and investigation holds override every date. Implemented in `config/compliance.php` (policy, git-versioned) plus `legal_holds` and `retention_log` (operational facts).

**2a. De-identification — refined 2026-09-03.** Two corrections to the above.

**Closure starts the clock; it does not license destruction.** The record stays lawfully identifiable for the whole retention period and is acted on only once `anchor + months + grace` has passed, and only if no hold covers it. `Beneficiary::retentionAnchorDate()` returns null for an open case, which is what stops a live case being swept up.

**The "keep" side needed widening.** Act 843 covers a person identifiable from the retained data *combined with other information held*, so stripping the name is not sufficient — `Legacy of Love / Widower Support / GHS 4,735 / 13 March 2026 / Tamale` singles out one person with no name in it. The boundary therefore has three dispositions, not two:

| | |
|---|---|
| **destroy** | name, phone, email, ID numbers and documents, date of birth, address, **community**, coordinates, photograph, signature, bank and MoMo details, next of kin, household, medical, religion, school/employer, narrative, case notes, uploaded documents, IP, **case reference**, **payment reference** |
| **generalise** | age → band · assistance amount → band · assistance date → period (month) · programme (subject to the minimum-group rule) |
| **keep** | division, region, district, gender, broad coded outcome, statistical indicators |

Community, case reference and payment reference are on the destroy side deliberately: a reference kept "for traceability" is exactly the linkage that makes everything else pseudonymous rather than anonymous.

**Two datasets, not one.** `beneficiaries` is operational and is destroyed at retention expiry; `beneficiary_impact_records` is anonymous, projected at case closure, and outlives it. Its `source_beneficiary_id` is `ON DELETE SET NULL`, so the linkage is severed in the same statement that destroys the case record — pseudonymous while the identifiable record lawfully exists, genuinely unlinked afterwards. A hash would not do: holding the means to reverse it keeps the data personal under Act 843.

**Minimum group size 5.** Not mandated by Act 843 — a disclosure control, because the Foundation works with small populations in sensitive categories. `DisclosureControl` suppresses any published breakdown cell below it, and nulls every measure on a suppressed row, not just the count.

Every column on a de-identifiable model maps to a classified element, and a test fails on any that maps to nothing. The failure mode being guarded is not a wrong decision about a field; it is a field added in two years that nobody classified at all.

**4. Shop sells branded merchandise, stationery, drinkware, books and campaign goods only.** Medicines, regulated medical products, supplements, food and cosmetics require a separate FDA Ghana regulatory review and cannot be listed without one. **Shop sales and charitable donations are separate throughout** — separate accounting, receipts, payment records and reporting. A charitable acknowledgement is never issued for a purchase.

### Gap sweep — 2026-09-04

A pass over every table named in the ERDs, every seeded permission, every feature flag and every documented `.env` key, checking each one against what actually exists. It found seven gaps, all now closed.

The pattern is worth stating, because it is the reason `CLAUDE.md` now carries a standing rule about it: **each gap read as a feature to anybody auditing the code.** A permission that grants nothing, a flag switched on with nothing behind it, an env key annotated "never optional" that nothing reads — those are worse than an obvious absence, because somebody has looked at them and believed them.

| Gap | State before | Now |
|---|---|---|
| **EXIF/GPS on uploads** | `MEDIA_STRIP_EXIF=true` in `.env.example` since Phase 2, annotated *"GPS in beneficiary photos — never optional"*, read by nothing | Stripped synchronously on upload; `Media::isPublishable()` refuses anything unsanitised |
| **Delivery webhooks** | `markBounced()` / `markComplained()` built with nothing to call them | `inbound_webhook_events` + signed endpoint per provider |
| **Audit archive** | Open question 16 | `audit_archives` + `scghf:archive-audit-log`, chain intact across the gap |
| **`pledges`, `payouts`** | Named in §2.4, never built | Built. A pledge is not income; a payout needs two people and evidence |
| **`fundraisers`** | `donations.fundraiser_id` promised in a Module 4 migration comment, never added | Built with its column, behind the p2p flag |
| **`sponsorships`** | `features.sponsorship` **on** with nothing behind it | Built, consent-gated per disclosure |
| **`event_tickets`, `product_reviews`** | Named in §2.5/§2.6; `reviews.moderate` seeded over a table that did not exist | Built |

**Sponsorship is the one to read the code for.** It links a named adult to a named vulnerable child and then sends that adult photographs and news about them indefinitely — done carelessly, a system for introducing strangers to children and telling them where to find them. So: three separate permissions all defaulting to false; consent re-read from the `consents` table at the moment of sending rather than cached; the consent actually relied on stored on the update; approval by somebody other than the author; the child generalised to a first name, an age band and a district; and **no column anywhere for a route from sponsor to child** — absent, not disabled.

**Two things found while building, both real:**

- The audit verifier accepted the **first** entry's `previous_hash` unconditionally, so deleting the oldest entries would have passed verification. It now checks that against null, a pruned archive, or an explicit `--from`.
- GD stamps `CREATOR: gd-jpeg` into every JPEG it writes, so counting that as metadata made every sanitised image look unsanitised again — re-encoded on every pass, losing quality each time, never converging.

### Answered 2026-09-04

**13. SMS provider is mNotify; sender ID is `GreaterHope`.** Implemented as `MnotifyGateway` — the only class in the application that talks to mNotify, on the same terms as `PaystackService`.

`GreaterHope` is **eleven characters, which is exactly the GSM maximum**. One more and the networks reject it, silently. The boot guard refuses to start production with an over-length sender ID for that reason, and the value must match the registration *exactly*, casing included.

Three things the gateway is careful about:

- **Accepting is not delivering.** mNotify accepting a message says only that mNotify accepted it. An unregistered or lapsed sender ID is accepted by the provider and dropped by MTN/Telecel/AT with no error returned anywhere — so `send()` yields `sent`, and only a delivery report moves a row to `delivered`.
- **An unrecognised response is a failure.** Treating an unknown code as "probably fine" is how a provider changing its API goes unnoticed until somebody asks why nobody got their receipt.
- **Account-level failures are told apart from message-level ones.** No credit, a bad API key or a rejected sender ID breaks *every* message, so those are logged `critical` rather than failing quietly one message at a time.

`scghf:sms-delivery-reports` runs hourly and is the only way a dead sender ID becomes visible: it looks like a total collapse in delivery while sending continues to report success. Below 60% delivered over 20+ reported messages the command exits non-zero, naming the sender ID, so cron emails somebody.

`SMS_DRIVER` stays `log` until `MNOTIFY_API_KEY` is in the server's `.env`; production refuses to boot with `mnotify` selected and no key, since that configuration cannot send a single message. **Still worth confirming with mNotify before the first live send:** that the registration covers all three networks, and that the delivery-report response shape matches what the gateway parses — it is written defensively and degrades to "still don't know", but a verified shape is better than a defensive one.

### Remaining

| # | Question | Blocks |
|---|---|---|
| 5 | **Multi-currency ever?** Schema supports it; if the answer is a firm no, some validation tightens. | several |
| 6 | **Is recurring mobile money viable on this merchant account?** Recurring charges need a *reusable* authorization. Paystack issues those readily for cards; for MoMo it depends on the network and the account's configuration. If MoMo authorizations are not reusable, recurring giving is card-only — or becomes a "remind me to give again" flow. Confirm with Paystack once the merchant account is open. | recurring giving on the channel most donors use |
| 7 | **Who signs an acknowledgement?** `general.receipt_signatory` has a placeholder default. The document goes to the GRA, so the wording of the authorised signature is the foundation's call. | issuing acknowledgements |
| 8 | **Delivery zones and rates.** All sixteen regions are seeded as zones, but **inactive and with no rates** — what delivery costs is a commercial arrangement with a courier, and inventing a price would put a figure in front of a customer nobody agreed to honour. The shop cannot take a delivered order until at least one zone is switched on with a rate. Collection works today. | shipping physical goods |
| 9 | **Who reviews a regulated product?** A flagged product needs a review recorded against a reference (FDA correspondence, a licence number, or a board minute). Which role holds that authority is a governance decision. | listing anything the keyword screen flags |
| 10 | **Which safeguarding checks does Ghanaian law actually require, and who may sign them off?** The software enforces a check set the moment one is defined, and refuses to approve a volunteer without it. What is currently configured — declaration, Ghana Police Service clearance, two references taken up, interview — is a defensible default, not advice. Confirm with the Department of Social Welfare. | recruiting volunteers for any role with vulnerable-person contact |
| 11 | **How long should a safeguarding record be kept?** Set to 6 years after a volunteer leaves. Some jurisdictions keep them far longer, precisely so an allegation made years later can be investigated against what was known at the time. A trustees' decision with advice. | the retention sweep, once volunteers exist |
| 12 | **Does a spam complaint stop receipts as well as appeals?** Currently yes — `complaint` maps to scope `all`, because continuing to mail somebody who reported us to their provider is what gets a domain blocklisted, and a blocklisted domain stops delivering everything. The cost is that their next receipt is not delivered. It is still logged and still raises a task, so Finance can post it or hand it over. The alternative — complaint suppresses marketing only — keeps receipts flowing at some reputational risk. **This is the one entry in the suppression policy that is a judgement call rather than a technical fact.** | how a complaint is handled; one line in `config/communications.php` |
| 14 | **Open and click tracking: on or off?** Off, deliberately. Turning it on records that a named person read a message, when, and roughly from where — Act 843 processing needing its own lawful basis and its own line in the privacy notice. The columns exist so it is a config change rather than a migration. A trustees' decision, not a default to inherit. | campaign open-rate reporting |
| 15 | **Who performs the quarterly restore test, and where?** `backups_log` refuses a restore test with no row count and no named verifier, so this cannot be ticked off — somebody has to actually restore an archive somewhere and count what came back. The staging subdomain has its own database and is the obvious target. Until the first one is recorded, the dashboard will keep saying the backups are a hypothesis, which is accurate. | proving the backups work; nothing else |
| ~~16~~ | ~~**Should a donor be able to turn on two-factor authentication?**~~ **Answered 2026-09-05: yes, optional.** Built — enrolment on `/account/security`, a challenge on the sign-in path, single-use recovery codes. Still not *required* for donors, so `UserType::requiresTwoFactor()` is unchanged: mandatory for staff, offered to everybody else. The account is never authenticated while the challenge is on screen, and the challenge is rate limited on account + IP. | closed |
| ~~17~~ | ~~**How does a donor change the email address on their account?**~~ **Answered 2026-09-05: self-service, in three steps.** Built — the current password, a confirmation link the NEW address must open, and a warning to the OLD one carrying a cancel link that also ends every session. `email` is untouched until the second step, so an attacker who gets that far has changed nothing and has left a message in the owner's inbox. Staff can still change it in the panel. | closed |

None block starting module 1. **15 is worth scheduling now** — it is the only item on this list that cannot be satisfied by writing code.
