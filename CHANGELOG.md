# Changelog

All notable changes to this project are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions are phase-based until launch, then [SemVer](https://semver.org/).

---

## [Unreleased]

### Phase 3 — Module 4, Fundraising — 2026-09-03

#### Added

**The gateway boundary**
- One payment path for donations *and* shop orders, via a polymorphic payable.
  Two payment paths is how a ledger diverges from the gateway
- **Webhook is truth.** The redirect only says the donor came back; the
  HMAC-authenticated endpoint says the money arrived. Raw body, `hash_equals()`,
  stored before parsing, queued, 200 returned immediately
- Answers **200 even to a forged request** — Paystack retries anything that is not
  2xx, so a 401 turns a probe into a retry storm. The exception is a failure to
  *store* the event, which returns 500 so it is not lost
- Idempotency lives in the database: `payment_webhook_events.event_id` is UNIQUE,
  so a replayed `charge.success` is a no-op even if the PHP handling it is wrong.
  An event with no id falls back to a hash of its body
- `PaymentTransaction` stores **expected** and **actual** separately. A mismatch
  becomes `mismatch` — not `success` (wrong figure) and not `failed` (the money
  may have been taken). It alerts and waits for a human
- `FeeCalculator` holds the rate in basis points, never a float. Gross-up solves
  `charge − fee(charge) = intended` rather than adding the fee to the original,
  which always leaves the foundation short. Property-tested at 18 amounts
- Card data: allow-listed authorization fields, a refusal to store more than four
  digits, and a scrubber that redacts card-shaped **values** at any depth
- Refunds are their own rows, need a stated reason, cannot exceed what remains,
  and **cannot be approved by the person who requested them**
- `FakeGateway` makes the module buildable before the merchant account exists —
  and verifies signatures with the same HMAC-SHA512, because faking that would
  leave the most security-critical line untested. **Production refuses to boot**
  on the fake driver or a test key

**Donations**
- Append-only, enforced: a completed gift refuses to have its amount, cause,
  reference, currency or deductible subtotal changed, and none can be deleted
- `donation_items` for **every** gift. A GH₵ 500 donation can be GH₵ 300
  deductible and GH₵ 200 not, so the subtotal is a sum over items
- Items are reconciled against the total **before** the gateway is called
- `is_tax_deductible` is snapshotted from `TaxDeductibility` and cannot be
  rewritten. A gift stays deductible after the approval lapses — the
  acknowledgement in the donor's hands must not change meaning
- Settlement is idempotent under a row lock; totals increment atomically in SQL
- Donors are separate from users, matched by email then normalised phone, never
  by name. Their details are snapshotted onto each gift
- Consent captured per channel with its Act 843 evidence

**Acknowledgements**
- Sequential per financial year with **no gaps**, from a counter row under
  `lockForUpdate` — not an AUTO_INCREMENT, which burns a number on a rolled-back
  insert
- Every figure and sentence **snapshotted**, never re-rendered
- The tax wording covers the **deductible subtotal**, not the gross
- Refuses a gift that has not completed, a payable on the never-acknowledge list,
  and a missing Foundation TIN

**Recurring giving**
- ⚠ **The mobile-money caveat is surfaced, not hidden.** Recurring charges need a
  reusable authorization; Paystack issues those readily for cards but not
  reliably for MoMo — which is how most Ghanaian donors pay. The default is
  conservative, blocked subscriptions are **reported** in the run summary and as
  skipped charge rows, and the decision is the foundation's to make
- Two drivers: `gateway` (Paystack owns the schedule) and `managed` (we charge a
  stored authorization from cron). A gateway-driven one is never charged locally
- Overlapping cron runs cannot double-charge — unique on
  `(subscription_id, scheduled_on)`
- One decline sets `failing`; three consecutive failures pause it. Any success
  clears the counter. A cancelled commitment refuses to resume
- Deductibility snapshotted fresh per cycle; every cycle gets its own receipt

**Offline gifts and reconciliation**
- Cash, cheques and bank transfers go into the **same** ledger and number series
- Reconciliation recovers payments the gateway settled that the site never heard
  about, and verifies once more before writing off an abandonment
- Mismatches, unprocessed webhooks and missing acknowledgements are reported,
  never auto-fixed. `--series` finds gaps in a year's receipt numbers
- Both commands registered in the **scheduler**, not as new cron lines. The
  retention sweep is scheduled **dry**

#### Fixed
- **The donation append-only guard did nothing.** It compared
  `getOriginal('status')` — a cast enum — against a string. `getRawOriginal` now
- `received_on` had no date cast; settlement was copying the offline method onto
  the donation's `channel`

646 tests, 1357 assertions.

### Phase 3 — Module 3, Programmes — 2026-09-03

#### Added

**Divisions and projects**
- `divisions` is the spine — the four divisions from the foundation profile, seeded
  and **locked**: projects, causes, donations and years of reporting hang off them,
  so deletion is refused and deactivation offered instead
- Each division names a **theme token** for its colour, never a hex. A hex in a data
  column would bypass `theme_settings` and its AA contrast validation exactly as a hex
  in a Blade template would; a test asserts every division names a token that exists
- `division_id` is nullable everywhere and **null means foundation-wide, not unknown**.
  `forDivision()` therefore includes the shared rows by default. Foreign keys are
  `ON DELETE SET NULL`: content outlives the structure
- The eight CMS tables Module 2 deferred gain `division_id` **with its foreign key**,
  as that migration promised — expand-only, constraint and column together
- `projects`, `project_updates`, `project_milestones`, `project_locations`, and pivots
  to focus areas, partners and documents. Budgets are integer pesewas through
  `MoneyCast`; a float budget is refused rather than silently stored a hundredfold low

**Causes and impact**
- `causes` is the fundraising unit, `projects` the work — separate tables, because a
  cause can fund several projects and a project can run with no appeal behind it
- **General Fund** seeded and locked, so a gift made with no appeal chosen still has a
  destination. `Cause::generalFund()` throws rather than returning null
- Per-division funds seeded as drafts with no copy: the foundation writes its own
  appeal text
- Progress is not clamped at 100% — an appeal that raised 140% should say so
- `impact_metrics` + `impact_metric_values` as a **time series**, not a running total,
  with who verified each figure and when

**Beneficiaries, consent and analytics**
- **Two datasets.** `beneficiaries` is operational and destroyed at retention expiry;
  `beneficiary_impact_records` is anonymous, projected at case closure, and outlives
  it. `source_beneficiary_id` is `ON DELETE SET NULL`, so the linkage is severed in
  the same statement that destroys the case record
- **Closure starts the retention clock; it destroys nothing.** An open case has no
  anchor date, which is what stops a live case being swept up
- `consents` — polymorphic, evaluated from its dates. A story needs story consent; a
  photograph needs photo consent as well; a real name needs name-use consent on top.
  Enforced in the model's saving hook, so `update(['is_published' => true])` cannot
  walk past it. `missingConsents()` says *which* consent is missing
- A minor's consent is refused unless it names the guardian who gave it, and a minor
  cannot consent on their own behalf
- Sensitive documents get 24 months against the case record's 72, and a medical or
  identity document is sensitive whatever the flag says
- `Beneficiary::privacyElements()` classifies every column; a test fails on any column
  that maps to nothing

#### Changed
- **The GRA acknowledgement wording.** The document is an *acknowledgement of
  contribution/donation to a worthwhile cause*, not a "tax-deductible receipt" — the
  Foundation acknowledges the gift, the GRA decides the deduction. Three paragraphs:
  s.97 status (only while a valid approval is held), the acknowledgement itself with
  the amount in figures and words, and the mandatory s.100 disclaimer
- **Approval validity is evaluated against the donation date, not today.** A receipt
  reprinted after an approval lapses still cites what was true when the gift was
  received; a gift received before approval never acquires it retroactively; a revoked
  approval is never cited
- **`causes.is_tax_deductible` is necessary but not sufficient.** Deductibility also
  requires a current written GRA approval; `qualifiesForTaxRelief()` delegates to the
  single gate rather than reading the column
- **The de-identification boundary gained a third disposition.** Destroy / generalise /
  keep, because an exact amount and an exact day beside a division and a district
  identify a person with no name in the row. Community, case reference and payment
  reference moved to destroy
- `AmountInWords` is pure PHP rather than `ext-intl` — intl is not guaranteed on shared
  cPanel hosting, and a legal document whose wording changes when the host upgrades PHP
  is not acceptable

#### Fixed
- **Fixture tables in tests caused a full `migrate:fresh` before every test.** MySQL
  implicitly commits on DDL, ending the transaction `RefreshDatabase` wraps each test
  in; Laravel then resets its migrated flag, re-migrates, drops the fixture table, and
  the test recreates it. Thirteen seconds per test. Fixture tables moved to
  `tests/database/migrations`, loaded only in the testing environment
- **`de-identify` was not idempotent** — a NOT NULL column got a fresh random redaction
  marker on every run. Already-redacted values are now skipped
- **`AGGREGATION_LATEST` returned the earliest figure.** The `values()` relation carries
  an ascending order for display, and appending `orderByDesc` does not override it

468 tests, 982 assertions.

### Phase 3 — Database architecture — 2026-09-02

#### Added

**Money foundation**
- `App\ValueObjects\Money` — integer minor units plus a currency, immutable. No float
  anywhere inside it and no public way to get one out: `percentage()` uses bcmath,
  everything else is integer arithmetic, `toMajorString()` returns a string
- `allocate()` / `allocateEvenly()` distribute the rounding remainder one pesewa at a
  time, so split or designated giving reconciles to the total exactly
- `App\Casts\MoneyCast` — refuses anything that is not a `Money` or an integer of
  minor units, so a stray `50.00` cannot be written as if it were 50 pesewas
- 53 tests including the cases that justify the class: `0.1 + 0.2` summing to exactly
  30 pesewas, and 1,000 additions of GH₵ 0.07 landing on exactly GH₵ 70.00

**Data architecture** — `docs/PHASE-3-DATA-ARCHITECTURE.md`
- ~150 tables across eight modules, with cardinalities, in dependency order
- Conventions: `BIGINT` internal key + ULID public identifier + human reference;
  `BIGINT UNSIGNED` pesewas; `VARCHAR` + PHP enum rather than MySQL `ENUM`; indexed
  string columns sized against the utf8mb4 3072-byte index limit
- Financial tables are append-only — corrections are new rows, never edits
- One payment path for donations *and* shop orders via a polymorphic payable
- Binding migration-safety policy: expand-only within a deploy, because migrations
  run before the release symlink flips and `rollback.sh` does not undo schema

**Module 1 — Core identity & authorisation**
- `users` carries staff and donors; `login_histories` records failed attempts too
- Ghanaian phone numbers normalised to E.164 on write, raw input retained
- `UserType` / `LoginOutcome` enums, `User` and `LoginHistory` models, factories
- `RoleAndPermissionSeeder` — 101 permissions across 10 roles, encoding the
  Blueprint §7.1 capability matrix as data. Idempotent, so it runs on every deploy
- 72 tests, 135 assertions, verified against real MySQL 8.4

#### Fixed
- **A deactivated user kept every permission their role granted.** The suspension
  guard was a `Gate::before`, but spatie/laravel-permission registers its own and
  package providers boot before app providers — so spatie returned true for a held
  permission and short-circuited ours. The guard now lives on the model as a
  `hasPermissionTo()` override, which every path routes through. The
  suspended-super-admin test had been passing for the wrong reason.
- **The Admin role was granted `payments.view_keys`**, contradicting the comment
  beside it: `fundraising.*` sweeps up the whole group. Added a `!permission`
  negation applied after wildcard expansion, with a test asserting each holds.
- Dropped a redundant standalone index on `users.type` — leftmost column of both
  composites, so it cost write time for no read benefit.

#### Local environment
- MySQL 8.4.9 initialised and bound to `127.0.0.1` only. The winget package installs
  binaries but never runs Oracle's configurator, so there was no data directory and
  no service; `scripts/dev-mysql.ps1` starts it without administrator rights
- `phpunit.xml` points the suite at MySQL rather than SQLite, so schema constraints
  SQLite does not enforce are caught locally
- `tests/Pest.php` added — `pest:install` had never been run, so Feature tests were
  not bound to Laravel's `TestCase`

### Phase 2 — Environment, repository & deployment pipeline — 2026-09-02

#### Added

**Repository scaffolding**
- `.editorconfig`, `.gitattributes` (LF everywhere, `export-ignore` so docs and CI never ship to the server)
- `.gitignore` covering secrets, built assets, backups and SQL dumps — donor data must never reach Git
- `.gitmessage` commit template with the `type(scope): subject` convention
- `pint.json` — Laravel preset with project overrides
- `README.md`, `CHANGELOG.md`

**Environment**
- Complete `.env.example`: app, logging, database, session/cache/queue, filesystem and media,
  mail (incl. bulk throttling), SMS (driver-agnostic), Paystack, money policy, security,
  backups, SEO/indexing, 16 feature flags, Vite
- Every key annotated with where its value comes from — cPanel, Paystack, SMS provider, or a project decision

**CI/CD**
- `.github/workflows/ci.yml` — PR gate: Pint, migrations against real MySQL 8, Pest,
  Vite build, **gzipped asset budget (100 KB JS / 50 KB CSS)**, secret scan
- `.github/workflows/deploy.yml` — `main` → production, `develop` → staging.
  Builds `vendor/` and assets on the runner so the server needs neither Composer nor Node.
  rsync over SSH port 2222 → migrate → warm caches → atomic symlink flip → smoke test →
  automatic rollback on failure
- `.github/PULL_REQUEST_TEMPLATE.md` with conditional checklists for money/auth/webhooks,
  UI and dark mode, uploads and beneficiary consent, and migrations
- `.github/dependabot.yml` — weekly grouped updates targeting `develop`; major framework
  bumps excluded as planned work

**Deployment**
- `deploy/scripts/bootstrap-server.sh` — idempotent one-time server setup: directory skeleton,
  shared storage, PHP binary and extension check, deny-all `.htaccess` on the app root,
  docroot symlink with automatic backup of existing content
- `deploy/scripts/activate.sh` — the atomic release switch. Verifies the upload, links shared
  state, **refuses to deploy to production with `APP_DEBUG=true` or a Paystack test key**,
  migrates, warms caches, generates environment-appropriate `robots.txt`, sets permissions,
  flips the symlink, prunes to 5 releases
- `deploy/scripts/rollback.sh` — one symlink move back, with an explicit warning that
  migrations do not roll back with the code
- `deploy/cpanel/cron.txt` — scheduler and `flock`-guarded queue worker for production and staging
- `deploy/cpanel/.cpanel.yml` — documented fallback, with its costs stated

**Server hardening**
- `public/.htaccess` — HTTPS and canonical-host redirects, dotfile blocking (with `.well-known`
  preserved for AutoSSL), sensitive-file denial, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`, cross-origin policies, CSP in report-only mode,
  Deflate/Brotli, immutable caching for fingerprinted assets, WordPress probe blocking.
  HSTS present but commented until SSL is confirmed on every host

**Documentation**
- `docs/PHASE-2-RUNBOOK.md` — 11 steps, every cPanel click and terminal command, with
  expected output and a failure table for each
- `docs/DEPENDENCIES.md` — justification per package, shared-hosting risk, rejected
  alternatives, and three decisions still open

#### Decisions recorded
- Deployment strategy **A** (GitHub Actions → SSH/rsync) confirmed viable; SSH enabled on port 2222
- `robots.txt` generated at deploy time from `APP_ENV` rather than committed — staging can
  never inherit production's file, and a static file survives a 500 where a route would not
- Migrations run **before** the symlink flip, so a failure leaves the live site untouched.
  This makes expand-only migrations a hard requirement
- `PAYMENT_DRIVER=fake` and `SMS_DRIVER=log` are the defaults, so Phases 3–8 proceed
  without Paystack or SMS credentials

#### Local toolchain — resolved
- Herd 1.30.0 installed (winget `BeyondCode.Herd`; the ID is not `Laravel.Herd`). PHP **8.4.25**
  and Composer **2.10.2** confirmed, all 16 required extensions present.
- Composer has no winget package; the winget PHP packages ship with every extension commented
  out. Runbook Step 0 rewritten with both routes and a tested `php.ini` extension-enabling script.
- **`config.platform.php` pinned** so Composer resolves the lockfile for the server, not the
  workstation — see the stack decisions below for the final value.

#### Application installed
- Laravel **13.30.1** skeleton generated and merged over the Phase 2 overlay with
  `robocopy /XC /XN /XO` (copy-if-absent), preserving every Phase 2 file byte-for-byte
- Filament **5.7.8** · Livewire **4.4.3** · Pest **5.1.3** · PHPUnit **13.3.1** ·
  spatie: permission 8.3.0, medialibrary 11.23.6, activitylog 4.12.3, backup 10.3.2,
  honeypot 4.7.2, sluggable 4.0.3, sitemap 8.2.0
- `php artisan test` green (2/2) · `pint --test` green · migrations run

#### Stack decisions — both forced by dependency resolution, not preference
- **PHP 8.3 → 8.4** (approved). On 8.3, Pest is not installable at all:
  `pest-plugin-laravel` v5 needs PHP ^8.4; Pest 4 conflicts with the PHPUnit 12.5 that
  Laravel 13 ships; Pest 5 needs PHPUnit 13, which needs PHP ≥ 8.4.1. Separately,
  `spatie/laravel-sitemap` has no version compatible with both PHP 8.3 and Guzzle 8.
  Platform pinned to `8.4.1`; `require.php` set to `^8.4`; both CI workflows and all
  cron/deploy paths moved to `ea-php84`. `CLAUDE.md` amended.
- **Livewire 3 → 4.** Filament v5 requires Livewire 4, so the two locked entries in
  `CLAUDE.md` could not both hold. Phase 4 onward must use Livewire 4 idioms.
- **Pint config relaxed** to the plain Laravel preset. The `concat_space` and
  `trailing_comma_in_multiline` overrides conflicted with Laravel's own generated code,
  which would have made every `artisan make:` output fail CI until hand-fixed.

#### Hosting — direction changed, 2026-09-02
- **New hosting will be procured, running PHP 8.4. The project will not launch on `presti98`.**
- Closes risks **SH-18, OPS-9, OPS-11 and OPS-12** outright rather than accepting them.
  Blueprint §4.4.1 and decision #29 marked superseded; the §11.6.1 portability rules are
  retained as standing engineering rules.
- Runbook split: **steps 0–6 are host-independent and proceed now**; steps 7–11 (cPanel
  config, server bootstrap, cron, SSL, first deploy) are **deferred** until the host exists.
- The pipeline architecture is unchanged — build on runner, rsync over SSH, atomic release
  symlink, rollback script. Only values change: `SSH_HOST`, `SSH_PORT`, `SSH_USER`,
  `DEPLOY_PATH`, `PHP_BIN`, `APP_URL`, the docroot path, and cron syntax if not cPanel.
- The `ea-php84` patch-version and extension checks are moot for now; they carry over to
  whatever host is chosen.

#### Local database
- Local development stays on SQLite for the remainder of Phase 2, and **moves to MySQL 8.4
  before Phase 3** — runbook step 2.5 added. SQLite tolerates index key-length limits,
  `ENUM`, lax `ALTER TABLE` and unenforced foreign keys in ways MySQL does not, which is
  precisely what Phase 3's schema work needs to surface.
- CI's MySQL service bumped **8.0 → 8.4** (8.0 reached EOL in April 2026).

---

## Phase 1 — Discovery & architecture blueprint — 2026-09-01

### Added
- `PHASE-1-BLUEPRINT.md` v1.3 — brand tokens with every WCAG 2.2 AA ratio computed,
  sitemap, role matrix, eight user journeys, 42 modules, 60-entry risk register,
  environment plan, and the §0 placeholder register

### Confirmed
- cPanel `presti98`, home `/home/presti98`, docroot `/home/presti98/greaterhopefoundations.com`
- PHP 8.3 system default (8.4 available) · SSH port 2222 · 200 GB disk · 2 GB PMEM · 80 entry processes
- Brand: deep green `#0B4D3F` + orange `#FC6302`; logo blue `#0068EC` reassigned to the
  Every Soul Missions division accent

### Accepted risks
- SH-18 / OPS-9 / OPS-11 — the foundation runs as an addon domain on a shared cPanel account
  whose primary domain is an unrelated business. Migration to dedicated hosting deferred;
  portability rules in §11.6.1 keep the eventual move cheap
