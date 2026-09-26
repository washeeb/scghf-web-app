# Testing and QA — what is automated, what a person does, and how a bug moves

Companions: `PHASE-8-PAYMENTS-TEST-PLAN.md` (money), `PHASE-13-ACCESSIBILITY-REPORT.md`
(the audit), `PHASE-12-SECURITY.md` (the threat model the tests defend).

## 1. The automated suite

| Suite | Where | Count (Phase 14) | Runs |
|---|---|---|---|
| Unit — pure logic, no framework | `tests/Unit` | Money, fees, amount-in-words, contrast | every commit |
| Feature — HTTP, database, Filament, jobs | `tests/Feature` | ~1,900 tests, 75 files | every commit, and again before every deploy |
| Browser — Chromium through Playwright | `tests/Browser` | 11 journeys | every pull request, own CI job |
| Static analysis — Larastan level 5 | `phpstan.neon.dist` | 258-line baseline | every commit |
| Coverage — the feature suite under pcov | CI job *Coverage* | floor `COVERAGE_MIN` | every pull request |
| Structural accessibility | `AccessibilityTest` | 21 public pages + 404 + account | every commit |
| Admin access matrix | `AdminAccessMatrixTest` | 57 URLs × 8 roles + guest, donor, bare staff | every commit |

### 1.1 Running it

```bash
php artisan test --exclude-testsuite Browser        # what the deploy gate runs (~16 min)
```

```bash
php artisan test --testsuite Browser                # needs `npm ci && npm run build && npx playwright install chromium`
```

```bash
vendor/bin/phpstan analyse                          # ~10 min cold, ~30 s warm
```

```bash
php artisan test --exclude-testsuite Browser --coverage --min=60   # needs pcov or xdebug
```

One file: `php vendor/bin/pest tests/Feature/PaymentsTest.php`. One test:
`--filter="replayed webhook"`. The suite uses the `scghf_test` database
and rebuilds it per test; never run two suites at once against it.

### 1.2 What is deliberately tested more than once

Money is tested at every layer it crosses, on purpose:

1. `MoneyTest` / `FeeCalculatorTest` — the arithmetic, with no framework.
2. `PaystackClientTest` — what goes on the wire to Paystack and what is read
   back, against a faked HTTP layer: pesewas, `currency: GHS`, the bearer
   token, retries, the allow-listed authorisation, scrubbing.
3. `PaymentsTest`, `GivingFlowTest`, `ShopCheckoutTest`, `RecurringGivingTest`,
   `ReconciliationTest` — the ledger, through the fake gateway that signs a
   real webhook.
4. `CriticalPathsTest` (browser) — a person completes a donation and a
   purchase, in Chromium, and the ledger agrees.
5. `PHASE-8-PAYMENTS-TEST-PLAN.md` §2–3 — a person with Paystack test keys,
   then one live cedi. Recorded in §5 of that document.

### 1.3 What is not automated, and why

- **A screen reader.** NVDA/VoiceOver need a person; the runbook is in the
  accessibility report. The structural audit and axe (in the browser suite)
  catch the regressions that can be caught without one.
- **Lighthouse.** Scores depend on the host. Run on staging after any change
  to the layout, images or scripts; the budget is in `PHASE-13-SEO-AND-CONTENT.md`.
- **Real email and SMS delivery.** The dispatcher, templates and gateways
  are tested against fakes. Whether Resend and mNotify deliver is a
  question for the health page and the delivery reports, not the suite.
- **Safari and Firefox.** The browser suite runs Chromium. The manual plan
  below covers the others.

## 2. Quality gates

### 2.1 Static analysis

Larastan at level 5. `phpstan-baseline.neon` holds what was true when
analysis was switched on (Phase 14): 258 entries, mostly properties the
analyser cannot see on a generic `Model` and nullsafe calls on relations it
believes are never null. **A new error fails the build; a baselined one is
not fixed by being there.** When touching a file with baselined entries,
fix them and regenerate:

```bash
vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon
```

The baseline count should only go down. Raising the level is a task in
its own right, not a side effect.

### 2.2 Coverage

The *Coverage* job runs the feature suite under pcov, uploads the HTML
report as a build artifact (14 days), and fails under the floor in the
repository variable `COVERAGE_MIN`, defaulting to **60 %**.

Why 60 and not a measured number: no coverage driver is installed on the
development machine, so the first true figure comes from the first CI run.
The floor is set where it cannot fail a suite of this size and shape by
accident. **After the first green run**, read the percentage from the job
summary and set `COVERAGE_MIN` to five points under it (Settings →
Secrets and variables → Actions → Variables). From then on it only goes up.

Coverage measures which lines ran, not whether anything was asserted about
them. It is a floor against drift, not a target to chase — the tests worth
writing are the ones in §1.2, and they are written.

### 2.3 Branch protection

`.github/branch-protection.json` requires three checks on `main` and
`develop`: **Lint, analyse, test**, **Coverage**, **Browser tests**. Apply
it with the command in the file once the repository is on a plan that
allows protected branches on a private repository.

### 2.4 Before a release

The deploy workflow re-runs pint, the feature suite and the secret scan
against the commit it is about to ship. It does not run the browser suite
(Chromium on a deploy runner is a cost with no new information: the PR
already ran it) or coverage.

## 3. The manual test plan

### 3.1 The matrix

Every journey in 3.2, in every cell that applies. A cell is *pass* when
the journey completes and nothing looks broken; *fail* otherwise, with a
bug report (§6).

| | Chrome (Win/Mac) | Firefox | Safari (Mac) | Android Chrome | iOS Safari |
|---|---|---|---|---|---|
| Light theme, desktop 1280+ | ● | ● | ● | | |
| Dark theme, desktop 1280+ | ● | ● | ● | | |
| Light theme, phone 360–414 | | | | ● | ● |
| Dark theme, phone 360–414 | | | | ● | ● |
| Tablet 768–1024, either theme | ○ | | ○ | ○ | ○ |
| Keyboard only (no mouse) | ● | ● | | | |
| Screen reader | NVDA + Firefox | | VoiceOver + Safari | | VoiceOver |
| 200 % zoom / large text | ● | | | ● | |
| Slow 3G (DevTools throttle) | ● | | | ● | |

● required before launch · ○ once, then only after a layout change.
The phone rows matter most: they are where the donors are. Test them on
a real low-end Android (a 2 GB device on MTN data), not only in DevTools.

### 3.2 The journeys

Each is written as what a person does and what they should see. The
automated equivalents are named so nobody re-tests by hand what a machine
already proved — the manual pass is for *looks right and feels right*, on
browsers the machine does not run.

**J1 — Give once.** Home → Donate → choose GH₵ 50 → name, email, phone
`024 123 4567` → consent → Continue → pay (test card) → thank-you page
names the amount and the appeal → receipt email arrives within a minute
with a PDF → *Finance → Donations* shows it completed.
(Automated: `GivingFlowTest`, `CriticalPathsTest`.)

**J2 — Give monthly by card.** As J1 with *Monthly*; thank-you says
monthly; *Finance → Regular gifts* shows the plan; `scghf:charge-recurring`
(dry run) lists it for next month.

**J3 — Give by Mobile Money on the page.** Donate → *Pay from a wallet* →
MTN → number → the waiting page says *approve the prompt on your phone*;
after approval it refreshes to the thank-you on its own.

**J4 — Give offline, recorded by staff.** Finance → Donations → *Record
an offline gift* → cash, GH₵ 200, a donor with an email → receipt sent →
the appeal total rises.

**J5 — Buy.** Shop → a product → Add to basket → basket → checkout with
a Ghanaian address and GPS code → pay → order page → order email with
invoice → *Shop → Orders* shows it paid → mark packed, shipped → the
customer gets each email.

**J6 — Buy for collection.** As J5 with *Collect*; no shipping charged;
the order page says where and when.

**J7 — Read.** Home → a project → its updates → an appeal → News → a
post → search for a word from it → the FAQ → an event → register →
confirmation email with the QR ticket → the door page scans it.

**J8 — Ask.** Contact form with and without a phone number → the
auto-reply → the message in *Engagement → Messages* → reply from there
→ the reply arrives → the SLA badge on an unanswered message after the
configured hours.

**J9 — Join.** Newsletter from the footer → confirmation email → confirm
→ preferences page → unsubscribe from one link → the popup does not show
again on that browser.

**J10 — Volunteer.** A role → apply → the application in *Community →
Volunteer applications* → shortlist → interview → accept → the volunteer
appears on the roster **not cleared** until the police check is recorded
→ a shift → hours after completion.

**J11 — Account.** Register → verify email → sign in → giving history →
download a receipt → change password → export my data → delete my
account → the confirmation → signing in fails.

**J12 — Staff, first day.** Sign in as a new staff account → forced 2FA
enrolment → the dashboard → every menu item the role should see and none
it should not (the matrix in `AdminAccessMatrixTest` is the reference) →
idle timeout after the configured minutes.

**J13 — Edit content.** Change the header phone number in *Settings* →
it changes on every page → edit the home hero → publish a draft page →
it appears in the menu and the sitemap → set a redirect → the old URL
redirects → upload an image with a person in it → it cannot be published
without a consent record.

**J14 — Theme.** Toggle system/light/dark in the header → every page in
J7 in both → no flash on reload → the toggle survives a new tab.

**J15 — Break it.** Submit every form empty → each field says what is
wrong, in place, and the first error is focused → paste a script tag
into a text field → it is text on the page, not a script → a URL with a
made-up id → the 404 page, with the search box → the same URL as staff →
the 404 log lists it.

### 3.3 Breakpoints to look at, per page kind

320 (small phone, also the WCAG reflow width) · 375 · 414 · 768 · 1024
· 1280 · 1536. What to look for: nothing cut off, no horizontal scroll,
tap targets not touching, the menu usable, images not stretched, the
donate button visible without scrolling on a phone.

### 3.4 Paystack on staging

`PHASE-8-PAYMENTS-TEST-PLAN.md` §2, recorded in §5 of that document. It
needs Paystack **test** keys in staging's `.env` and the staging webhook
URL in the Paystack dashboard. No run has been recorded yet.

## 4. UAT — the checklist for the foundation

Written for the people who will use the site, not for developers. Tick
each line on the staging site with the demo data loaded (§7). If a line
cannot be ticked, write down what happened and what you expected, in the
words you would use to a colleague, and send it (§6).

**As a visitor**

- [ ] I can find out what the foundation does within two clicks of the home page.
- [ ] I can give GH₵ 50 by card in under two minutes on my phone.
- [ ] I can give by MTN Mobile Money without leaving the site.
- [ ] I get a receipt by email, and it has the foundation's name and a number on it.
- [ ] I can buy something from the shop and I get told when it is on its way.
- [ ] I can read about a project and see what has changed lately.
- [ ] I can send a message and I get an acknowledgement.
- [ ] I can sign up to the newsletter and unsubscribe again.
- [ ] I can apply to volunteer.
- [ ] The site is readable in the dark theme and switches when I ask it to.
- [ ] Nothing on the site is out of date, misspelled or a placeholder.

**As staff**

- [ ] I can sign in and I was made to set up the authenticator app.
- [ ] I can see the gifts that came in today and what they were for.
- [ ] I can record a cash gift a donor handed me and they get a receipt.
- [ ] I can change the phone number in the footer myself.
- [ ] I can write a news post, save it as a draft, and publish it later.
- [ ] I can see the orders that need packing and mark one as sent.
- [ ] I can reply to a message from the contact form.
- [ ] I can see the volunteers who are cleared to work with children and those who are not.
- [ ] I cannot see the parts of the admin that are not my job.
- [ ] I can find the manual for the thing I am trying to do.

**As the treasurer**

- [ ] Every gift on the Paystack dashboard for the test period is on the Donations page, once.
- [ ] The reconciliation report says zero "needs review".
- [ ] A refund needs two of us.
- [ ] The receipts numbers run in order with no gaps.

## 5. Load sanity on shared hosting

The site lives on shared cPanel hosting, which limits a single account
by **entry processes** (concurrent PHP requests, typically 20–30), CPU
seconds, IO and inodes — not by requests per second. A load test here is
not about throughput; it is about finding the point at which the host
starts queueing requests, and making sure a normal busy day is well
below it.

**What "busy" is for this foundation:** an appeal shared on WhatsApp
reaching a few hundred people in an hour; a radio mention; the launch.
Fifty people on the site at once with ten of them on the donate form is
the realistic peak. Design the test around that, not around thousands.

**How to run it** (against staging, from a machine that is not the
server, with the demo data loaded):

```bash
# 20 concurrent visitors, 60 seconds, the pages a real visitor hits
k6 run --vus 20 --duration 60s scripts/load/browse.js
```

If `k6` is unavailable, ApacheBench does for the read pages:
`ab -n 500 -c 20 https://staging.…/` and `.../donate` and `.../shop`.

**What to read:**

| Measure | Where | Healthy |
|---|---|---|
| p95 response time, read pages | k6 summary | < 800 ms at 20 VUs |
| p95 response time, `/donate` POST | k6 summary | < 1.5 s at 10 VUs (the gateway call dominates) |
| Errors (5xx, timeouts) | k6 summary | 0 |
| Entry processes | cPanel → *Resource Usage* | never at the limit |
| CPU / IO faults | cPanel → *Resource Usage* → snapshots | none during the run |
| Queue lag | *Site Health* → Queue | jobs cleared within the minute after the run |

**What not to test on shared hosting:** anything that would look like an
attack to the host's own protection (hundreds of VUs, minutes of
sustained POSTs). The account gets rate-limited or suspended, and the
number learned is the host's limit, not the site's.

**What to do with the numbers:** if p95 on read pages is over budget,
the first fix is Cloudflare's edge cache in front (Phase 12 doc), which
takes every static file and, with a page rule, the home page off PHP
entirely. The second is OPcache settings in the PHP selector. Nothing in
the application is worth optimising before those two.

The `scripts/load/` directory holds `browse.js` (home, an appeal, a
project, a post, the shop, the FAQ; think time 3–8 s) and `donate.js`
(the donate form, submitted to the **fake** gateway — never run this one
against a Paystack-keyed environment; it would create real test
transactions at rate).

## 6. Bug triage

### 6.1 Report it

GitHub → Issues → *New issue* → **Bug report** or **UAT finding**. The
templates ask for what is needed: what you did, what happened, what you
expected, where (URL), on what (browser, phone), and a screenshot. A
report without steps to reproduce is a conversation, not a bug.

Anything involving money — a gift not counted, counted twice, the wrong
amount, a receipt that is wrong — is reported **and** messaged to the
developer the same day, whatever the severity table says.

Security issues are **not** filed as issues. `SECURITY.md` says where
they go.

### 6.2 Severity

| Severity | Meaning | Examples | Response |
|---|---|---|---|
| **S1 Blocker** | Money or trust is at risk, or nobody can give | a gift not recorded, a webhook rejected wrongly, the site down, a data leak, the donate form failing on a major phone | same day; fix before anything else; hot-fix to `main` |
| **S2 Major** | A journey cannot be completed by some people | checkout broken in Safari, an email not sent, staff cannot sign in, a form that refuses valid input | within the week; next release |
| **S3 Minor** | Wrong but there is a way round | a typo in a template, a layout gap on one breakpoint, a wrong sort order | scheduled; batched |
| **S4 Cosmetic** | Nobody is blocked | spacing, a colour a shade off, an icon | when convenient |

Severity is the reporter's first guess and the triager's decision; it is
fine to be wrong in either direction.

### 6.3 The board

Labels: `bug`, `uat`, `S1`–`S4`, `money`, `a11y`, `security` (for the
label only — the report itself goes through `SECURITY.md`), `needs-repro`,
`wontfix`. Every open S1/S2 is looked at at the start of every working
session on the code. An S1 with no fix in a day gets the workaround
written on the issue and told to the people affected.

### 6.4 Fixing it

Every bug fix lands with a test that fails without the fix — for
anything under `app/Payments`, `app/Http/Controllers/Webhooks` or the
policies this is not optional (CLAUDE.md). The pull request names the
issue, the CI runs the whole suite, and the issue is closed by the merge
with a one-line note of what the cause was. A bug that reappears is
reopened, not re-filed.

## 7. Demo data

```bash
php artisan db:seed --class=DemoDataSeeder
```

Adds, on top of what `DatabaseSeeder` seeds for every environment: nine
staff accounts (one per role, password `password`, no 2FA yet), four
projects with updates, three appeals partly raised, six posts, four
products with stock, three events, three volunteer roles and four
volunteers, trustees, testimonials, partners, 36 gifts across six months
through the real offline-gift service, eight paid orders, twelve
subscribers. Idempotent; refuses to run in production. Demo gifts carry
no receipts (a receipt is an email to an address that belongs to nobody).

## 8. Open items

| Item | Who | Why it is open |
|---|---|---|
| First Paystack staging run | a trustee with the Paystack account | needs test keys; `PHASE-8-PAYMENTS-TEST-PLAN.md` §5 |
| `COVERAGE_MIN` from the first measured run | developer | no coverage driver on the dev machine; §2.2 |
| Branch protection applied | repository owner | GitHub plan; §2.3 |
| Screen reader and Lighthouse pass on staging | anyone with NVDA or a Mac | needs a person; `PHASE-13-ACCESSIBILITY-REPORT.md` |
| A real low-end Android on MTN data | anyone in Ghana | the matrix row that matters most |
