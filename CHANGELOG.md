# Changelog

All notable changes to this project are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions are phase-based until launch, then [SemVer](https://semver.org/).

---

## [Unreleased]

### Phase 3 — Module 8, System — 2026-09-04

**Phase 3 complete.** Eight modules, ~150 tables, 973 tests, 2014 assertions.

#### Added

**An audit trail that records reads, not just writes**
- `audit_logs` captures ACTIONS, including the ones that change nothing —
  reading a beneficiary's medical history, exporting donor records, running the
  retention sweep. spatie/laravel-activitylog records model *changes*; none of
  those are changes, so until now none left a trace
- Events are declared in `config/system.php` with a category and a severity, and
  recording an undeclared one **throws** — a new export screen has to be
  classified before it can log
- **Volume escalates severity.** One donor record viewed is somebody doing their
  job; two thousand exported is a question that needs asking the same day
- Append-only and **hash-chained** — an edit or a deletion breaks every hash
  after it, and `scghf:verify-audit-log` names the first break. It makes
  tampering *detectable, not impossible*; the head hash is anchored to the
  application log on every clean run, which is what an auditor can check against
- Impersonation is recorded separately from the person impersonated
- The actor's name is snapshotted, so the trail survives them leaving
- **Auditing never breaks the action it audits** — a failed write goes to the
  log off-database and the caller proceeds

**API tokens**
- Hashed and shown once; a leaked database is not a leaked set of credentials
- **No abilities by default**, and a mistyped ability is refused rather than
  silently granting nothing
- Beneficiary, safeguarding, donor and refund abilities can never be granted
- A **mandatory expiry**, with an expiring-soon list so an integration does not
  stop working on a Saturday with nobody knowing why

**Errors, grouped**
- Keyed by a fingerprint of class, file, line and the message with its variable
  parts normalised, so a failing page does not produce a row per visitor
- **Nothing from the request body, ever.** Route parameter names, never values
- Ordinary traffic — 404s, failed logins, validation — is not an error
- A fault that recurs after being resolved **reopens**

**Backups**
- `backups_log` records **restore tests** as first-class rows. One with no row
  count verified nothing; one with no named verifier is an assertion. Both are
  refused
- `healthWarnings()` says "no backup has ever been restored and verified" —
  which is true, and stays true until somebody does one
- `file_count` beside `size_bytes`, because shared hosting counts inodes

**Visitor statistics**
- Aggregate **by construction**: no IP, no fingerprint, no cross-site
  identifier, no per-visitor row. A test asserts the exact column list
- Counts after the response and swallows every failure — statistics are never
  worth a millisecond on a 3G connection, and never worth an error page
- Path only, never the query string, which is where tokens and email addresses
  live. Referring host, never the full referrer
- **This application cannot report unique visitors**, and that is the trade

**Feature flags**
- The database overrides a flag; it cannot invent one. `config/features.php`
  stays the source of truth for which flags exist
- Every override carries a **reason** — required for switching on as much as
  off — and an **expiry**, which is what stops a temporary measure becoming
  permanent
- A lapsed override is simply not loaded, so nothing has to run to expire one
- `donations` is locked against admin-panel override
- Falls back to config when the table cannot be read

**Settings history**
- Hooked into the `Setting` model, not into Filament, so it holds however the
  value is changed
- Encrypted settings record *that* they changed, with both values redacted
- `valueAt()` answers "what did the receipts we issued in March say?"
- No foreign key to `settings`, so the history survives the setting

#### Notes

- New commands: `scghf:verify-audit-log` (daily, quiet when clean),
  `scghf:sms-delivery-reports` (hourly), plus a daily error-table prune
- Two open questions recorded: who performs the quarterly restore test and
  where, and where the audit trail is archived from year three

### Phase 3 — mNotify — 2026-09-04

#### Added

- **`MnotifyGateway`** — the SMS provider the foundation chose, sending under the
  registered sender ID `GreaterHope`. The only class that talks to mNotify, on
  the same terms as `PaystackService`
- **Accepting is never read as delivering.** mNotify accepting a message says
  only that mNotify accepted it; an unregistered or lapsed sender ID is accepted
  by the provider and dropped by MTN/Telecel/AT with no error returned anywhere
- An **unrecognised response code is a failure**, not a shrug. Treating an
  unknown response as "probably fine" is how a provider changing its API goes
  unnoticed until somebody asks why nobody got their receipt
- Account-level failures — no credit, bad API key, rejected sender ID — are told
  apart from message-level ones and logged `critical`. They break *every*
  message, and the queue would otherwise fail quietly one message at a time
- Remaining SMS credit is read from every send and warned on below a threshold.
  Credits are pre-paid and running out is silent
- **`scghf:sms-delivery-reports`**, hourly. The only way a dead sender ID becomes
  visible: it looks like a total collapse in delivery while sending continues to
  report success. Below 60% delivered over 20+ reported messages it exits
  non-zero, naming the sender ID, so cron emails somebody
- `ReportsDelivery` contract, separate from `SmsGateway` because `log` genuinely
  cannot answer the question

#### Changed

- `SMS_SENDER_ID` default is now `GreaterHope` — eleven characters, exactly the
  GSM maximum, and it must match the registration including casing
- Production refuses to boot with `SMS_DRIVER=mnotify` and no `MNOTIFY_API_KEY`.
  That configuration cannot send a single message, and finding out one failed
  receipt at a time is the expensive way
- `SMS_DRIVER` stays `log` until the key is in the server's `.env`

#### Notes

- Still worth confirming with mNotify before the first live send: that the
  registration covers all three networks, and that the delivery-report response
  shape matches what the gateway parses. The parsing is defensive and degrades
  to "still don't know", but a verified shape beats a defensive one

### Phase 3 — Module 7, Communications — 2026-09-03

#### Added

**The suppression list, as a gate rather than a report**
- `suppressions` — one row per address per channel, and every send checks it.
  Blueprint risk DEL-4: without it, bounces accumulate until the sending domain
  stops being delivered, and the first casualty is the donation receipt
- **Suppression has a scope.** `all` stops everything including receipts;
  `marketing` stops appeals only. An unsubscribe is `marketing` — somebody who
  no longer wants appeals has not asked to stop receiving the record of a gift
  they just made
- Suppression only ever **strengthens** automatically. Coming off the list needs
  a named person and a recorded reason; an address suppressed under an Act 843
  objection cannot be released at all
- Addresses are **normalised** before storage — lowercased email, E.164 phone.
  `Ama@Example.com` and `ama@example.com` are one mailbox, and five spellings of
  a number are five chances to text somebody who asked not to be
- Deliberately **excluded from the retention sweep**. Forgetting that somebody
  objected is how they start receiving mail again after asking not to

**Templates, editable but not everywhere**
- `email_templates` and `sms_templates`, keyed, CMS-editable, with declared
  variables. The seeder refreshes structure on every deploy and **never**
  overwrites wording the foundation has changed
- The GRA acknowledgement paragraphs stay in `config/compliance.php` and reach
  the template as one `{{acknowledgement}}` variable — nobody can reword a
  statement made under s.97 of Act 896 by editing an email in a browser
- Rendering **refuses** on a missing required variable. "Dear ," cannot be
  recalled; a failed job can be fixed in five minutes
- Placeholders are not Blade. A database column rendered as Blade is arbitrary
  PHP execution one compromised admin account away from being somebody else's
- A **locked** template can be reworded but not deactivated or deleted. The
  failure guarded is quiet: somebody tidies the list, receipts stop, nothing
  reports an error
- Subject lines are stripped of newlines — a donor-supplied name containing one
  turns whatever follows into a header of its own

**SMS, costed before a provider exists**
- `SmsSegmenter` measures encoding, septets and segments properly: GSM-7
  extension characters cost two, emoji cost two UCS-2 units, and a multipart
  message loses seven bits per segment to the concatenation header
- **The cedi sign is not in GSM-7.** `CLAUDE.md` mandates "GH₵ 1,234.56" for
  display, so the correct format for the website roughly triples the cost of
  every SMS sent from a template containing it. `SmsTemplate` refuses to save a
  template that blows its segment budget, and names the character responsible
- `PhoneNumber` normalises every way a Ghanaian number gets typed to E.164, and
  **refuses** a nine-digit pre-2010 number rather than guessing — the migration
  inserted a digit, and a guessed number sends a receipt to a stranger
- `SMS_DRIVER=log` writes a complete, segmented, network-attributed, costed row
  and sends nothing, so a month of running the site estimates what SMS will cost
- `sent` is never read as `delivered`. An unregistered sender ID is accepted by
  the provider and dropped by the network with no error anywhere

**Logs, the outbox and the throttle**
- `email_logs`, `sms_logs`, `notification_logs` — every attempt recorded
  **including the refusals**. A suppressed receipt produces a row explaining
  itself so Finance can post it or hand it over
- `scheduled_messages` is the outbox: visible, cancellable, priority-ordered and
  **expirable**. A backlog on this host is measured in days, and a queue that
  delivers "the event is tomorrow" three days late is worse than one that
  delivers nothing and says why. Receipts never expire
- Claimed under `lockForUpdate` with a TTL, because cron starts a worker every
  minute and the previous one may still be running
- `idempotency_key` is unique — a replayed webhook collides instead of producing
  a second receipt
- **The throttle is a `COUNT`, not a counter.** It counts rows in `email_logs`
  with `sent_at` inside the window: atomic without a lock, self-correcting after
  a killed worker, and incapable of drifting from what was actually sent
- `scghf:send-messages`, scheduled every minute, drains what the host's hourly
  cap allows and stops. Exits non-zero when the oldest message has waited six
  hours — the number that actually matters here, not the queue length

**Newsletters** *(deferred out of Module 6; they needed templates and suppressions)*
- `newsletters`, `newsletter_campaigns`, `campaign_recipients`, plus three
  seeded lists so `subscribers.topics` means something concrete
- **Consent is re-checked per message, not per campaign.** At two hundred an
  hour a campaign takes most of a day; somebody who unsubscribes in hour three
  has unsubscribed
- Two gates before a campaign can go: a **test send** must have happened, and an
  **approval** recorded by somebody holding `newsletter.send`. Editing the
  content afterwards withdraws both
- A campaign can be **paused** mid-flight — only meaningful because 1,600
  messages are still waiting
- Every campaign email carries an RFC 8058 one-click unsubscribe, and a
  marketing message with no working unsubscribe link is **refused** rather than
  sent without one
- Skips are recorded with a reason rather than deleted, so "why did I not get
  the newsletter?" has an answer

**Retention and privacy**
- New class `communication_log` — 24 months from send, then deleted. Delivery
  evidence has a shorter life than the financial record it relates to, which
  lives under `financial_record` independently
- Open and click tracking are **off**. Recording that a named person read a
  message is Act 843 processing needing its own lawful basis; the columns exist
  so enabling it is a config change, not a migration

#### Changed

- `bootstrap/providers.php` registers `CommunicationServiceProvider`, which
  refuses to boot production with an over-length SMS sender ID

#### Notes

- 895 tests, 1867 assertions. `config/communications.php` carries the policy;
  three open questions are recorded in `docs/PHASE-3-DATA-ARCHITECTURE.md`
- Only the `log` SMS driver is implemented. A stub that silently succeeded would
  be worse than none, because `log` at least tells the truth about what it did

### Phase 3 — Module 6, Engagement — 2026-09-03

#### Added

**Safeguarding, as a gate rather than a policy**
- `VolunteerApplication::approve()` **refuses** while any required check is
  outstanding, and names which ones. The failure guarded is not malice — it is
  approving a keen volunteer "and doing the police check next week"
- `involves_vulnerable_contact` defaults to **true**; an application with no
  opportunity is treated the same. A default of false would mean every role
  somebody forgot to configure quietly skipped its checks
- Roles with no contact get a lighter set — requiring a police check to hand out
  leaflets turns the requirement into a formality, and a formality is not a
  safeguard
- `safeguarding_checks` is one **row** per check with a reference, a date and a
  named verifier. A pass without either is refused; a waiver needs a stated
  reason *and* an authoriser
- **Clearances go stale.** A police certificate carries an expiry and
  `isCurrentlyCleared()` is computed from the dates, not read from a cached flag
- A concern **suspends immediately** — before investigation, without implying a
  finding. It insists the concern is written down, and insists on a written
  outcome before reinstating

**Volunteers**
- Hours in **minutes**, capped at a day, future-dated entries refused
- Only **verified** hours count towards the figure a funder is shown, and nobody
  can verify their own

**Events**
- Capacity counts **people, not bookings** — three guests take four places
- Over capacity **waitlists** rather than refusing; registration runs under a row
  lock so two people cannot both take the last two places
- `photography_consent` is **nullable with no default**: "never asked" and "said
  no" are different answers, and only the wrong one can be inferred from silence
- Three separate consents — photography, event contact, newsletter. A
  registration is not a mailing list
- Ticketing is feature-flagged off and the model refuses to mark an event
  ticketed while it is

**Prayer requests**
- **Confidential by default.** `is_confidential` true, `consent_to_publish`
  false, and publication needs both cleared. Enforced on every save, so
  `forceFill` cannot walk past it
- Anonymous by default even *with* consent — being happy for a situation to be
  prayed about publicly is not being happy to be named in it
- An anonymous request has its contact details stripped on creation

**Retention**
- New classes: declined volunteer application (12 months), withdrawn (6),
  volunteer record including safeguarding (72, sensitive), event registration
  (24 from the event ending), prayer request (12 from submission, sensitive)
- Accessibility and dietary needs classified as **health data**; a prayer
  request's text likewise

⚠ **The volunteer retention periods are defaults, not advice** — recorded as an
open question rather than presented as settled.

817 tests, 1722 assertions.

### Phase 3 — Module 5, Shop — 2026-09-03

#### Added

**Catalogue and the FDA guard**
- Every product save screens name, summary and description against
  `compliance.shop.prohibited_keywords`. A flagged product cannot go live until
  somebody records a review **with a reference** — "we looked at it" is not a
  review an auditor can follow up
- Screening runs on **every** save, so a published mug edited to mention a
  supplement is caught. An existing review is cleared when new flags appear that
  it did not cover
- A newly flagged save **unpublishes** rather than refusing — refusing would
  discard the editor's work and leave the older text live. An explicit
  `publish()` still throws, naming the keywords and the regulator
- Word-boundary matched: "creamery" does not trip "cream", "drugstore" does trip
  "drug"
- The taxonomy is **seeded from the compliance policy**, not duplicated, so the
  two cannot drift. Categories outside the agreed list are visibly marked

**Stock as an append-only ledger**
- `inventory_movements` with a reason on every row; `stock_on_hand` is a cache
  and `recalculateStock()` rebuilds it. An adjustment without a note is refused
- Holds reserve the shelf at **order time**, not at payment, so two customers
  cannot buy the last mug while both sit on the payment page

**Orders, on the shared payment path**
- `Order` is a `Payable` — same gateway, same verification, same mismatch
  handling. Two payment paths is how a ledger diverges
- `order_items` snapshot name, SKU and unit price; the variant FK is nullable
  with `ON DELETE SET NULL`, so a discontinued product takes no history with it
- Line totals are **derived**, never accepted from the caller
- Totals reconcile — lines to subtotal, and subtotal + shipping − discount to
  total — before the gateway is called
- Status history is append-only and attributed; a system change is marked as
  such rather than shown as nobody
- A paid order cannot be cancelled outright: the money has to go back, and a
  refund is its own record with its own approval

**Invoices — the separation rule, both directions**
- `SCGHF-INV-…`, its own counter table, never the `SCGHF-R-…` donation series
- `InvoiceIssuer` **asserts** before writing that the order could not lawfully
  receive a charitable acknowledgement
- Every invoice states in words that it is not a donation acknowledgement and
  cannot support a section 100 claim

**Shipping, coupons, downloads**
- All sixteen Ghanaian regions covered, seeded **inactive with no rates** — the
  regions are a fact, the prices are a commercial decision
- Collection is a zone with a zero rate, not a branch in the checkout
- Free-delivery thresholds compare against the **subtotal**, not the total
- Coupons in basis points, capped, never larger than the basket; a refused code
  returns a reason
- Digital downloads are long random tokens with an expiry and a use limit

#### Fixed
- **Abandoned checkouts stranded their stock.** Reconciliation marked the
  transaction abandoned but told the payable nothing. `Payable` gains
  `onPaymentAbandoned()`; a donation is marked abandoned, an order puts its
  goods back on the shelf
- `stock_held` is UNSIGNED, so `stock_held - 1` underflowed before
  `GREATEST(0, …)` could clamp it — a repeated release errored instead of being
  a no-op. Cast to SIGNED first

#### Changed
- `App\Models\Order` removed from `TaxDeductibility`'s pending-module list now
  that the class exists — keeping it would silence the warning that should fire
  if the model is ever renamed

746 tests, 1562 assertions.

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
