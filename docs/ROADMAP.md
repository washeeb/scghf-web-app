# Roadmap — phase two, proposed

What to build after launch, in what order, and why. **Nothing here is
built.** Each item is scored against what the code already has, and the
sequence at the end is a recommendation for the trustees to approve,
change or cut. When an item is approved it becomes a phase of its own
with a brief, tests, docs and a changelog entry, as the eighteen before it.

Scoring: **Value** for this foundation's donors and staff specifically
(not "nice to have" in general). **Effort** in developer-weeks at the
quality bar of the existing code, tests and docs included. **Risk** —
what can go wrong, including for money and for beneficiaries. **Has
already** — what exists in the code today, because half of these items
were anticipated in Phase 3's schema.

## 1. The items, scored

### 1.1 Progressive Web App — offline pages and add-to-home-screen

- **Value: high.** The audience is on low-end Android on flaky data. An
  installable icon and an offline page that says "you are offline — here
  is the MoMo number to give by" turns a dropped connection from a lost
  gift into a delayed one.
- **Effort: S (1 week).** A manifest, icons, a service worker that caches
  the shell and the last-seen pages and serves an offline page; the
  `FEATURE_PWA_OFFLINE` flag already exists (off, because nothing is
  behind it — Phase 17). No offline *giving*: payment needs a network.
- **Risk: low–medium.** A service worker that caches too much serves
  stale donation totals or a stale basket. Scope it to public read pages
  and never to `/donate`, `/basket`, `/checkout`, the account or the
  admin — the same exclusion list the page cache uses.
- **Has already:** the CSP nonce plumbing, the page-cache exclusion list,
  the flag, the theme-colour meta.
- **Depends on:** nothing.

### 1.2 USSD or short-code giving

- **Value: high — for reach, not for revenue at first.** Feature-phone
  donors cannot use the site at all today. A `*xxx#` menu that produces a
  MoMo prompt reaches them.
- **Effort: M (3–4 weeks)** plus a partner. USSD is an aggregator product
  (Hubtel, Nsano, Arkesel, or directly through a telco); each has its own
  API and a monthly fee and short-code lease. The build is a webhook
  endpoint that walks a menu (appeal, amount, confirm) and then issues a
  Paystack MoMo charge — the same `chargeMobileMoney()` the donate page
  uses — so the ledger, receipt (by SMS) and webhook path are unchanged.
- **Risk: medium.** Session state across USSD hops; menus time out in
  seconds; the aggregator's uptime is outside our control; receipts by
  SMS cost per gift. Money risk is low because settlement still comes
  through Paystack's webhook.
- **Has already:** `PaymentManager::chargeMobileMoney()`, SMS receipts,
  `Attribution` (source = ussd), the webhook path.
- **Depends on:** a short code and an aggregator contract (weeks of lead
  time; start the paperwork early), SMS credits.

### 1.3 WhatsApp Business — receipts, updates, enquiries

- **Value: high.** WhatsApp is the channel Ghanaian donors read; email is
  the one they ignore. A receipt on WhatsApp is read within minutes.
- **Effort: M (3 weeks).** A fifth channel on `MessageDispatcher`
  (templates approved by Meta, sent through the Cloud API or a BSP such
  as Twilio/360dialog), opt-in captured on the donate form beside SMS,
  suppression honoured, delivery events into the existing log. Enquiries
  (two-way) are a second step: inbound webhook into *Inbox → Messages*
  with a reply from the panel.
- **Risk: medium.** Template approval is slow and templates are rigid;
  the Business API charges per conversation; a blocked number is a
  blocked foundation. Consent must be explicit (Act 843 and Meta both).
- **Has already:** the dispatcher, the suppression list, templates with
  placeholders, `consent_sms` (add `consent_whatsapp`), the delivery-log
  screens.
- **Depends on:** a verified Meta Business account, a phone number not
  used on the WhatsApp app, template approval.

### 1.4 Donor portal upgrades

- **Value: medium.** The account already shows giving history, receipts,
  export and deletion, and a signed link manages a regular gift. What is
  missing is the *story*: an impact timeline ("your gifts, and what they
  did" from the cause updates and the impact figures), receipts archive
  by tax year, and managing the subscription without the emailed link.
- **Effort: S–M (2 weeks).**
- **Risk: low.** Read-only views over data that exists; the one write
  (subscription changes) already exists behind the signed route.
- **Has already:** `account.*` routes, `donations`, `donation_receipts`,
  `cause_updates`, `impact_metric_values`, `subscriptions` with pause
  and cancel.
- **Depends on:** nothing.

### 1.5 Public REST API and webhooks; a mobile-app backend

- **Value: low today, medium later.** No partner is asking. The value
  appears with a mobile app (1.1 covers most of what an app would do) or
  a partner dashboard.
- **Effort: M–L (4–6 weeks)** for a versioned read API (projects,
  appeals, totals, posts), authenticated write endpoints (a gift
  initialisation that returns a Paystack authorization URL), outbound
  webhooks (gift completed, appeal reached), rate limits, docs, a
  sandbox.
- **Risk: medium–high.** A second entry point into the money path; every
  rule in `PAYMENTS.md` has to hold there too. Token leakage; abuse.
- **Has already:** `api_tokens` (scoped, expiring, revocable, audited —
  Phase 3), `routes/api.php` does not exist yet, the policies, the
  `Payable` contract.
- **Depends on:** a real consumer. Build for one, not for "partners".

### 1.6 Grant and institutional-donor management

- **Value: medium–high for the foundation's growth**, invisible to
  donors. Grants are where the larger money is, and a spreadsheet of
  deadlines is how they get missed.
- **Effort: M (3–4 weeks).** Funders, opportunities with deadlines, a
  proposal pipeline (idea → drafting → submitted → awarded/declined),
  reporting obligations with due dates and reminders through the
  scheduler, documents attached, awards recorded as restricted funds
  against projects, and a report that shows spend against each grant
  from the expenditure log.
- **Risk: low.** Internal, no money moves; the risk is building a CRM
  nobody updates. Keep it to deadlines and documents.
- **Has already:** `expenditures`, `projects`, `documents`,
  `scheduled_messages` for reminders, the audit log.
- **Depends on:** the treasurer's actual grant list to design from.

### 1.7 Beneficiary case management

- **Value: high — and the most sensitive thing on this list.** The
  schema is complete (Phase 3: `beneficiaries`, `beneficiary_documents`,
  `beneficiary_impact_records`, `safeguarding_checks`), the encrypted
  columns, the retention classes with holds, the permissions and the
  audit of every read exist (Phases 11–12). **There is no admin screen.**
  Case records live on paper or in the safeguarding lead's head.
- **Effort: M (3–4 weeks).** Filament resources with the strictest
  policies in the application: field-level visibility by permission,
  every view audited (`AuditLogger` already records reads), documents
  behind signed short-lived URLs, no export except by the data-protection
  lead, no bulk actions, no soft-delete bypass, a consent record for any
  photograph, and the intake form as a private staff form (not public).
- **Risk: high if done casually.** A child's medical note visible to the
  wrong role is a safeguarding failure and an Act 843 breach. Build with
  the safeguarding lead in the room; test the access matrix for every
  role before the first real record.
- **Has already:** everything except the screens.
- **Depends on:** the safeguarding policy's data list agreed by the
  trustees (what is collected, who sees what).

### 1.8 Multi-currency display with GHS settlement

- **Value: medium.** Diaspora donors think in GBP/USD/EUR; a "≈ £8" beside
  GH₵ 150 removes a hesitation. Settlement stays GHS.
- **Effort: S (1–2 weeks)** for *display*: a daily rate feed cached, a
  `≈` figure beside every public amount, the gift still charged in GHS.
  **L** for *charging* in other currencies (Paystack supports USD for
  some Ghanaian merchants; needs a multi-currency Paystack setup and a
  `Money` that is not GHS-only, which touches every ledger table).
- **Risk: low for display; high for charging** (the ledger, receipts,
  reconciliation and the tax approval all assume GHS).
- **Has already:** `Money` with a currency field and a GHS-only exponent
  table; `currency` columns on every money table.
- **Depends on:** display — a rate source (e.g. the Bank of Ghana daily
  rates or an FX API). Charging — a Paystack account decision.

### 1.9 Multilingual content (English, Twi, Ga, Ewe, French)

- **Value: medium for Twi, low for the rest, at this size.** The
  foundation's donors are urban Ghanaians and the diaspora; the
  beneficiaries' languages matter for the *programme*, less for the
  donation site. French reaches Togo/Côte d'Ivoire partners.
- **Effort: L (6+ weeks)** for real translated *content* (every page,
  post and appeal in two languages, with the CMS editing both), plus the
  translation itself, which is the foundation's ongoing cost. **S** for
  the *interface* strings only (every string is already `__()`).
- **Risk: medium.** Half-translated sites read worse than English-only
  ones; `hreflang` and duplicate-content SEO; every content type's forms
  double.
- **Has already:** `__()` everywhere, `users.locale`,
  `FEATURE_MULTILINGUAL` (off), the SEO layer ready for `hreflang`.
- **Depends on:** a translator, and a decision to keep translating.

### 1.10 Accounting integration (QuickBooks / Xero / Zoho)

- **Value: medium.** The treasurer re-keys the monthly CSV today. A
  push of settled gifts, orders, fees and refunds as journal entries
  saves a day a month and removes typing errors from the accounts.
- **Effort: S for a formatted CSV/OFX export per accounting package
  (1 week); M for a live API sync (3 weeks, OAuth, mapping, idempotent
  posting, reconciliation of what was posted).
- **Risk: low–medium.** Double-posting; mapping fees and refunds
  correctly; which package the accountant actually uses.
- **Has already:** every figure (`ReconciliationService`,
  `GivingReports`, `ShopReports`, `payouts`), the CSV export.
- **Depends on:** the accountant's package and chart of accounts.

### 1.11 Donor segmentation, lifecycle automation, win-back

- **Value: medium–high after six months of data**, low before. A
  first-time donor thanked differently from a monthly one; a "we miss
  you" to somebody whose gifts stopped; a nudge before the tax-year end.
- **Effort: M (3 weeks).** Segments as saved filters on donors
  (recency, frequency, value, cause), journeys as scheduled rules
  (trigger → wait → message → stop conditions), all through the
  dispatcher with consent and suppression, and a report of what each
  journey produced.
- **Risk: medium.** Automation that mails people who asked not to be
  mailed — the suppression list covers the mechanism, the *tone* is the
  trustees' risk. Start with one journey (the lapsed-donor note).
- **Has already:** `donors.last_donated_at`, tags on donors, the
  newsletter builder, templates, the dispatcher, `Attribution`.
- **Depends on:** six months of live giving, and a decision on tone.

### 1.12 A/B testing on the donation form

- **Value: low at this traffic.** A test needs hundreds of gifts per
  variant to say anything; the site will not have that for a year.
- **Effort: S (1 week)** for the mechanism (a variant cookie, the form
  reading it, the conversion event carrying it, a report).
- **Risk: low**, but the risk of *acting* on noise is real.
- **Has already:** the analytics events (`donation_started/completed`),
  the page cache varying on cookies.
- **Depends on:** traffic. Revisit at 500 gifts a month.

### 1.13 Matching-gift and corporate-match campaigns

- **Value: medium when a corporate partner exists**, none before.
- **Effort: S–M (2 weeks).** A match rule on an appeal (partner, ratio,
  cap, window), the thermometer showing "your GH₵ 50 becomes GH₵ 100",
  the matched amount recorded as a pledge against the partner and
  invoiced at the end, the appeal total showing both.
- **Risk: low.** The match is a pledge, not a payment, until the partner
  pays; the ledger must never show it as received.
- **Has already:** `pledges`, `partners`, `causes.goal`, the progress
  component.
- **Depends on:** a partner willing to match.

### 1.14 Live campaign thermometer and public donation wall/screen mode

- **Value: medium, and cheap.** A projector at a dinner showing the
  total climb as guests give by MoMo is a real fundraising tool in
  Ghana.
- **Effort: S (1 week).** A `/screen/{appeal}` page: no header, huge
  numbers, the last five gifts (first name and town, consent permitting),
  auto-refresh every few seconds from a small JSON endpoint, dark by
  default for a projector.
- **Risk: low.** The endpoint is read-only and cached; the wall respects
  `is_anonymous`.
- **Has already:** `raised_minor`, the donor wall, the progress bar, the
  page cache (exclude `/screen`).
- **Depends on:** nothing.

### 1.15 Team/office management, staff intranet, board portal

- **Value: low–medium.** Trustees need papers, minutes and a calendar;
  Google Drive does that. The one thing the site could add is a board
  view of the dashboards (giving, impact, health) without giving trustees
  the admin.
- **Effort: S for a read-only *Board* role and page (1 week); L for an
  intranet (not recommended — it is a different product).
- **Risk: low.**
- **Has already:** roles and permissions; `AnalyticsPage`, the reports.
- **Depends on:** nothing.

### 1.16 AI-assisted content drafting inside the CMS, human approval required

- **Value: medium.** Staff write slowly and rarely; a draft of a project
  update from the field notes, or alt text suggested from the photograph,
  lowers the barrier. Approval stays human — the draft is a form field
  the editor edits and publishes, never auto-published.
- **Effort: S–M (2 weeks).** A "Draft with help" action on posts, project
  and appeal updates, and the alt-text field; a provider key in `.env`;
  a system prompt built from the foundation's own style (the seeded
  legal pages and the manual show the voice); nothing sent that is
  personal data (no beneficiary fields, no donor names); a usage cap.
- **Risk: medium.** Confident wrong facts in a project update; a
  beneficiary's name sent to a third party. Both are mitigated by what
  the action is allowed to read (published content and the editor's own
  notes only) and by the approval step.
- **Has already:** the CMS forms, `Html::clean()`, the media alt-text
  requirement, the audit log.
- **Depends on:** a provider account and a per-month budget; the
  trustees' view on it.

### 1.17 Also on the list, from the phases (not in the brief)

| Item | Value | Effort | Note |
|---|---|---|---|
| Cloudflare in front | high | hours | `PHASE-12-INFRASTRUCTURE.md` §2; the biggest speed and safety win available; needs the DNS change |
| Object storage for media | medium | S | when inodes pass 70 %, or with the VPS move |
| VPS migration | — | M | only on the signs in `PHASE-15-PERFORMANCE.md` §5 |
| IndexNow | low | hours | once publishing is weekly |
| Event ticketing (`FEATURE_EVENT_TICKETING`) | medium | S | the products, tickets and door page exist; the flag turns the paid path on — when a paid event is planned |
| Peer-to-peer fundraising (`FEATURE_P2P_FUNDRAISING`) | medium | M | `fundraisers` exists; needs the public page, the fundraiser's dashboard, moderation |
| Sponsorship (a child/family, `FEATURE_SPONSORSHIP`) | — | — | on and built; but it depends on 1.7 for the beneficiary side to be safe to use at scale |

## 2. Recommended sequence

Ordered by value to donors now, then by what unlocks the rest, then by
what needs a partner and lead time.

### Wave 0 — the first month after launch (no new features)

Cloudflare; `COVERAGE_MIN`, `MEDIA_INODE_BUDGET`, `PREFLIGHT_GATE` set from
real numbers; the Paystack staging run recorded; the thirty-day review.
**Nothing from the list until the review.**

### Wave 1 — months 2–3 (small, high-value, no partners)

1. **PWA** (1.1) — one week, immediate for phone donors.
2. **Live thermometer and screen mode** (1.14) — one week, in time for the first event.
3. **Donor portal: impact timeline and receipts archive** (1.4) — two weeks.
4. **Beneficiary case management** (1.7) — start the design with the
   safeguarding lead now; build in wave 2. It is the highest-value item
   for the *programme* and the one with the most to get right.

### Wave 2 — months 4–6 (partners and paperwork started in wave 1)

5. **Beneficiary case management** (1.7) — three to four weeks.
6. **WhatsApp receipts and updates** (1.3) — after Meta approval.
7. **Grant management** (1.6) — from the treasurer's real list.
8. **Accounting export** (1.10, the CSV first).
9. **Multi-currency display** (1.8, display only).

### Wave 3 — months 7–12 (needs data or a partner)

10. **Donor segmentation and the lapsed-donor journey** (1.11) — with six months of data.
11. **USSD giving** (1.2) — when the short code is leased.
12. **Matching gifts** (1.13) — with the first corporate partner.
13. **AI-assisted drafting** (1.16) — if the trustees want it.
14. **A read-only Board view** (1.15).

### Later, or on a trigger

**Multilingual** (1.9) — Twi interface strings first (S), content only
if a translator is funded. **Public API** (1.5) — when a real consumer
exists. **A/B testing** (1.12) — at 500 gifts a month. **Charging in
foreign currency** (1.8, the L half) — a Paystack account decision. **VPS
and object storage** — on the signs.

## 3. What each wave costs, roughly

| Wave | Developer-weeks | Third-party costs |
|---|---|---|
| 0 | 1 | Cloudflare free |
| 1 | 4–5 | none |
| 2 | 8–10 | WhatsApp Business API per-conversation fees; an FX rate feed (free tiers exist) |
| 3 | 7–9 | USSD short-code lease and aggregator fee (monthly); an AI provider budget |

Every item ships the way the first eighteen phases did: a brief restated,
tests for anything that touches money or personal data, the manual
chapter, the changelog, the launch check extended if it adds a flag.

## 4. Decisions needed before any of it starts

1. **Approve, reorder or cut** the waves above. Wave 0 needs no approval.
2. **Beneficiary data**: the trustees' list of what is collected and who
   sees what, before 1.7 is designed.
3. **WhatsApp**: a dedicated number and who owns the Meta Business
   account.
4. **USSD**: whether to start the short-code application (months of
   lead time) now.
5. **AI drafting**: yes, no, or not yet — it is a policy question before
   it is a technical one.
6. **Accounting package** the accountant uses (decides 1.10's shape).

## 5. What is deliberately not proposed

- A native mobile app: the PWA gives the icon and the offline page; the
  gap is small and the maintenance is not.
- A staff intranet: a different product; the tools exist.
- Cryptocurrency giving, NFTs, and similar: no donor has asked, the
  regulatory position in Ghana is unsettled, and the reconciliation story
  does not exist.
- Replacing Paystack: the integration is the most tested code in the
  repository; a second gateway is a second set of everything in
  `PAYMENTS.md`.
