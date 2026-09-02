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

### 2.7 Communications

`email_templates`, `sms_templates`, `email_logs`, `sms_logs`, `notification_logs`, `scheduled_messages`, `suppressions`.

**`suppressions` is not optional.** Hard bounces and complaints land here and every send checks it. Without it the list rots and the sending domain's reputation goes with it — Blueprint risk DEL-4.

`sms_logs` records segment count and estimated cost per message, so the `SMS_DRIVER=log` mode is genuinely informative before a provider exists.

### 2.8 System

`audit_logs`, `api_tokens`, `backups_log`, `feature_flags`, `error_reports`, `visitor_stats`, `settings_history`.

`visitor_stats` is aggregate counts only — no IP, no fingerprint, no cross-site identifier. Privacy-respecting by construction rather than by policy.

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

> ⚠️ **Still needed from you:** the exact wording the Ghana Revenue Authority requires on a deductible receipt, and which of the four divisions' causes qualify. The schema is ready for either answer; the copy is not something to guess at.

### Answered 2026-09-03

**2. Beneficiary data retention — purpose-based, per Act 843.** Declined applications 24 months from decision; incomplete or withdrawn 12 months from last activity; approved case records 6 years from closure, then de-identified rather than deleted so the financial trail survives while the person does not; medical and other highly sensitive supporting documents 24 months from closure — deliberately far shorter than the case record they support, because a report proving eligibility has served its purpose once the case closes; tax and accounting records a 6-year statutory MINIMUM and never auto-deleted; anonymised statistics indefinitely. Legal, audit and investigation holds override every date. Implemented in `config/compliance.php` (policy, git-versioned) plus `legal_holds` and `retention_log` (operational facts).

**4. Shop sells branded merchandise, stationery, drinkware, books and campaign goods only.** Medicines, regulated medical products, supplements, food and cosmetics require a separate FDA Ghana regulatory review and cannot be listed without one. **Shop sales and charitable donations are separate throughout** — separate accounting, receipts, payment records and reporting. A charitable acknowledgement is never issued for a purchase.

### Remaining

| # | Question | Blocks |
|---|---|---|
| 5 | **Multi-currency ever?** Schema supports it; if the answer is a firm no, some validation tightens. | several |

None block starting module 1.
