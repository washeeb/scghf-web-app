# St. Cecilia's Greater Hope Foundations — Web Application

Donation, fundraising and e-commerce platform for a Ghanaian foundation.
Laravel 13 · Livewire 4 · Filament 5 · Tailwind · MySQL · Paystack (GHS) · shared cPanel hosting.

> *"To turn remembrance into impact."*

---

## What this is

A production web application for a registered Ghanaian non-profit, built around four divisions:

| Division | Remit |
|---|---|
| **Life Spring Foundation** | Health |
| **BrightPath Fund Initiative** | Education |
| **Legacy of Love Initiative** | Orphans, Widows & Widowers |
| **Every Soul Missions** | Evangelism |

It accepts one-off and recurring donations in Ghanaian Cedis via Paystack (**Mobile Money first** — it is how Ghana pays), runs a fundraising shop, showcases projects and measured impact, and is fully editable by non-technical staff through a Filament CMS.

---

## Documentation map

**Start here** if you are new: `CONTRIBUTING.md`, then `docs/ARCHITECTURE.md`.

| For | Read |
|---|---|
| The standing rules and decisions | **`CLAUDE.md`** — read before changing anything |
| How the application is put together | **`docs/ARCHITECTURE.md`** — module map, request lifecycle, key services, decisions with reasons |
| The schema | **`docs/DATABASE.md`** — every table by module, the ER spine (generated; `docs/tools/schema_reference.py`) |
| Releasing and rolling back | **`docs/DEPLOYMENT.md`** — GitHub → cPanel, the activation script, the pre-deploy dump, rollback |
| Money | **`docs/PAYMENTS.md`** — Paystack end to end, every webhook event, the state machines, "paid but not recorded" |
| Every `.env` key | **`docs/ENVIRONMENT.md`** — generated from `.env.example` with the read/documented cross-check |
| Security | **`docs/SECURITY-MODEL.md`** (the model and the incident short form) · **`SECURITY.md`** (reporting) |
| Tests | **`docs/TESTING.md`** |
| When it breaks | **`docs/TROUBLESHOOTING.md`** — the twenty likely failures |
| Running it | **`docs/OPERATIONS.md`** — the calendar, monitoring, escalation, handover |
| Going live | **`docs/LAUNCH.md`** — pre-launch with owners and checks, launch day cut-over, the first thirty days, the backlog |
| What comes next | **`docs/ROADMAP.md`** — sixteen candidate features scored for value, effort, risk and dependencies against what the code already has, in waves; nothing built until approved |
| Third-party terms | **`docs/LICENCES.md`** |
| For the foundation's staff | **`resources/manual/`** — the admin manual, also shown inside the panel under *Help & manual* |
| What changed | **`CHANGELOG.md`** |

The phase records — how each part was built and what was decided along the way — are `docs/PHASE-*.md`: the blueprint (1), the setup runbook (2), the data architecture (3), the payments test plan (8), email and SMS (10), safeguarding (11), security, data protection, PCI and infrastructure (12), SEO and accessibility (13), QA (14), performance (15). `docs/DEPENDENCIES.md` says why each package is here.

---

## Local setup

**Requires PHP 8.4+, Composer 2, Node 20+.** On Windows, `winget install --id BeyondCode.Herd -e` supplies PHP and Composer together. See `docs/PHASE-2-RUNBOOK.md` Step 0 — and Step 2.5 for the local MySQL, which Phase 3 onward needs.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

Then `php artisan serve`. For a site with something on it, `php artisan db:seed --class=DemoDataSeeder` (never in production; it refuses). The admin is at `/scghf-office`; the demo Super Admin is `demo.superadmin@example.test` / `password`.

### Common commands

| Command | What |
|---|---|
| `php artisan serve` · `npm run dev` | the site on :8000 with hot-reloading assets |
| `php artisan test --exclude-testsuite Browser` | the deploy gate (~1,900 tests) |
| `php artisan test --testsuite Browser` | the Chromium journeys (`npm run build` and `npx playwright install chromium` first) |
| `vendor/bin/pint` · `vendor/bin/phpstan analyse` | style · static analysis (level 5, baselined) |
| `php artisan scghf:preflight` | what is still a placeholder or missing — run before any deploy |
| `php artisan scghf:launch-check` | preflight plus readiness: legal pages, content, the live gift, mail DNS, the certificate, staff 2FA, demo data, honest flags — exit 0 = go |
| `php artisan scghf:cache-clear` | empty the page and fragment caches (`cache:clear` alone is not enough) |
| `php artisan scghf:reconcile-payments` | compare our ledger with Paystack (dry run; `--execute` to settle) |
| `php artisan scghf:media-doctor` | what this server can do to images, and the inode budget |
| `php artisan scghf:db-maintain` | the monthly housekeeping (dry run; `--execute`) |
| `php artisan scghf:restore-test` | restore last night's backup into the scratch database and check it |
| `php artisan db:seed --class=DemoDataSeeder` | a believable site for staging (refuses production) |
| `node docs/tools/screenshots.mjs` | re-take the manual's screenshots |
| `python docs/tools/schema_reference.py > docs/DATABASE.md` · `python docs/tools/env_reference.py > docs/ENVIRONMENT.md` | regenerate the generated docs |

### Tests

```bash
php artisan test --exclude-testsuite Browser
```

That is the deploy gate. `vendor/bin/phpstan analyse` is the other one. The browser suite (`--testsuite Browser`) needs `npm ci && npm run build && npx playwright install chromium` first. All of it, and what a person tests by hand, is in **`docs/PHASE-14-QA.md`**.

Full first-time setup — including generating the Laravel skeleton — is **`docs/PHASE-2-RUNBOOK.md`**.

---

## The rules that are not negotiable

These are in `CLAUDE.md` in full. The short version, because getting them wrong costs money or trust:

**Money.** Every amount is an **integer of pesewas** (GHS × 100). Never a float. `GH₵ 50.00` is `5000`. Every Paystack call carries `"currency": "GHS"`.

**Payment truth comes from the webhook, never the browser redirect.** Verify `hash_hmac('sha512', $rawBody, $secret)` against `x-paystack-signature` with `hash_equals()`. Store the raw event, respond 200 immediately, process on the queue, and make processing idempotent — a replayed event must never double-count a donation.

**No hardcoded content in Blade.** No strings, phone numbers, emails, addresses, colours or images. Everything comes from the CMS layer. If you are about to hardcode real content, move it into settings instead.

**Tests are required** for anything touching money, auth, or webhooks. Not optional.

**Both themes, every component.** Light and dark, each checked for WCAG 2.2 AA contrast individually — not by inverting colours and hoping.

**Shared hosting.** No Docker, no root, no Redis, no Supervisor, no persistent Node process. Flag anything that needs them before writing it.

**Every message goes through `MessageDispatcher`.** One door out, so the suppression list, the logging and the hourly rate limit are true rather than merely available. A second send path would be a path with no suppression check on it — and mail to somebody who asked us to stop is what stops receipts being delivered to everybody else.

**Flagged means built, not noted.** If something is identified as needed, the schema goes in and it gets built in the same phase — see `CLAUDE.md`. A genuine deferral is a feature flag that is *off* plus a line in the open-questions table; anything else is a gap that reads as a feature to whoever audits it next.

**The theme comes from the database, and the server decides it.** Colour tokens live in `theme_settings` and are inlined into the head — changing the brand colour is an edit in the admin panel, not a deploy. A cookie carries the visitor's choice so the server can render `.dark` in the HTML itself; localStorage alone would mean every page paints light and then corrects itself, which is the flash.

**No image is published until its metadata has been removed.** A photograph taken on a phone carries the coordinates of where it was taken, which here is frequently a beneficiary's home. `Media::isPublishable()` refuses anything unsanitised, and "we have not checked yet" is refused the same way as "we checked and it failed".

**Reading personal data is an auditable action.** Anything that opens a beneficiary's file, exports records, or takes data out of the application records an entry through `AuditLogger`. Those actions change no model, so nothing else would notice them — and *who read this?* is the question that matters most for the records this foundation holds.

**Staff do not sign in at the public form.** Two-factor is mandatory for staff and it is enforced *inside Filament's login flow*. A public login form that authenticated a staff account would hand out a fully authenticated session on one factor — and `canAccessPanel()` would then let it into the admin panel, past the check, with nothing visibly wrong. `LoginController` redirects staff to the panel once they have proved the password, and `LoginRequest` constrains the attempt to donor accounts so a change in the controller cannot quietly reopen it.

**A form never says whether an address has an account.** Not sign-in, not password reset. The cost is real — somebody who mistypes their address is told a link is coming and it never arrives — and the alternative is a free service for enumerating this foundation's donors. The `email_logs` row is what lets support answer *did it go?* without the form having to.

**Verification is what earns the giving history.** `donors` is matched on email address, so an account that has not proved the address sees nothing. Attaching the record at registration would let anybody who types a known donor's address read what that person has given.

**A file in use cannot be deleted.** Thirty-two of the thirty-four foreign keys pointing at `media` are `ON DELETE SET NULL`, so deleting an in-use file does not fail and does not warn — a donation receipt loses its PDF, a beneficiary loses their ID document, a consent record loses the evidence it is evidence of, and each still reads as intact. `MediaUsage` discovers those columns from the schema rather than a hand-written list, and `Media::deleting()` refuses. There is no override: detach it, or use `MediaLibrary::replace()`, which keeps the id so every reference follows.

**An upload is judged by its bytes.** The filename, the extension and the `Content-Type` header are all supplied by whatever did the uploading. The type is sniffed and cross-checked against the extension in both directions, filenames collapse to a single dot so nothing lands as `x.php.jpg`, and SVG is refused outright — it is XML that can carry `<script>` from the same origin as the admin panel.

**An unsanitised image gets no conversions.** `isPublishable()` already refuses the original, but conversions sit at derivable paths on a public disk — generating them would be three more copies of a photograph still carrying a child's home coordinates. `scghf:regenerate-media-conversions` builds them once the file is clean.

**The navigation works with JavaScript switched off.** Every menu is a `<details>` element, because the browser already implements disclosure — announced state, keyboard operation, no script. `resources/js/navigation.js` adds Escape, click-away and closing on resize, and every one of those is *absent rather than broken* if the file never loads. That is the test to apply to anything added to it. These visitors are on low-end Android phones, sometimes behind data-saver proxies that rewrite scripts, and a navigation that needs JavaScript is a site they cannot move around.

**Changing an email address takes three steps, and one of them is a warning.** The current password, a link the *new* address must open, and an alert to the *old* one carrying a cancel link that ends every session. Until the second step, `email` is untouched — so an attacker with a stolen session who gets that far has changed nothing and has left a message in the owner's inbox. The giving history is deliberately *not* re-matched on a change: an inbox is not a claim on somebody else's donations.

**The second factor is a step, not a page.** The account is never authenticated while the challenge is on screen — what exists is an id in the session, re-read from the database each time. A factor somebody can skip by closing the tab is a suggestion. The secret waits in the session until a code proves it, so a populated `two_factor_secret` always means a factor the person can actually produce.

---

## Branches and deployment

| Branch | Deploys to | How |
|---|---|---|
| `main` | `greaterhopefoundations.org` | PR only, CI green, manual approval |
| `develop` | `staging.greaterhopefoundations.org` | PR from `feature/*` |
| `feature/*` | — | branched from `develop` |

Push → GitHub Actions runs the quality gate, builds `vendor/` and the Vite assets **on the runner**, rsyncs to cPanel over SSH port 2222 into a timestamped release directory, runs migrations, warms caches, then flips the `current` symlink. The server never needs Composer or Node.

Rollback is one symlink move: `deploy/scripts/rollback.sh`.

---

## Repository layout

```
app/  bootstrap/  config/  database/  public/  resources/  routes/  tests/   Laravel
.github/workflows/       ci.yml (PR gate) · deploy.yml (build + ship)
deploy/scripts/          bootstrap-server.sh · activate.sh · rollback.sh
deploy/cpanel/           cron.txt · .cpanel.yml (documented fallback)
docs/                    the handover set (see the map above), the phase records, tools/ (generators)
resources/manual/        the admin manual and its screenshots (shipped; shown in the panel)
```

---

## Contributing

`CONTRIBUTING.md` — the loop, the rules that trip people, what to document. In short: branch from `develop`, the test that fails without your change, `pint` and `phpstan` before you push, the PR template, CI green.

**Never commit a secret.** `.env` is ignored; `.env.example` documents every key. CI fails the build if a live key or a private key appears in tracked files.

---

## Status

**Phase 2 complete** — environment, repository, deployment pipeline.
**Phase 3 complete** — all eight modules landed: core identity · settings & CMS · programmes · fundraising · shop · engagement · communications · system, plus a gap sweep. ~131 tables.
**Phase 4 complete** — application foundation. The admin panel and its front door (Filament at a configurable path, mandatory TOTP, sign-in recording); a policy for every model with a test that keeps it that way; the layout shell (theme system, header and footer from the seeded menus, branded error pages); public donor accounts (register, sign in, verify, reset, account area); the media library, engine and admin screen both; and the navigation, as dropdowns on a laptop and an expanding panel on a phone. See `CHANGELOG.md`.

**Phase 5 complete** — the CMS. Landed: the page builder (blocks as drag-orderable rows, fields generated from the block registry, a closed vocabulary for per-block presentation, revisions with restore); block rendering (twenty block views, the public page route, and a signed staff-only preview for drafts); menus, theme and global content (the menu builder, the theme editor with the WCAG contrast checker in front of it at last, and a tabbed site settings screen over the whole settings table); the content modules (blog with moderated comments, galleries, documents, FAQs, testimonials, partners, team, announcements, the contact inbox, and redirects with a 404 log that feeds them); and the admin experience (a dashboard, global search, streaming CSV export on every content table, and a Site Health page that answers the questions shared hosting fails silently on — cron, queue, backups, storage, Paystack mode and SMS credits — with `scghf:preflight` giving the same answers to a deploy script). See `CHANGELOG.md`.

**Phase 6 complete** — the public site. Landed: the content pages (news, FAQs, galleries, reports, team, partners, testimonials, contact and search), the SEO layer that was half-built since Phase 3 (Open Graph, canonical, structured data, sitemap.xml and a robots.txt that finally honours the indexing switch), and the two forms whose tables had no way in — the contact form and the double-opt-in newsletter; and the programmatic pages (areas of work, projects with filters, appeals with live progress and a donor wall, plus the Mobile Money and bank details that had been seeded since Phase 3 and shown nowhere) with the admin screens to publish them; and the donation page (presets, giving levels, fee cover, tribute and monthly giving, with a sandbox checkout so the whole journey can finally be walked without live Paystack keys); and the shop (catalogue, basket, checkout with Ghana-shaped delivery, order confirmation that trusts only the webhook, and the admin screens for products, stock, delivery zones, discount codes and orders — plus the invoice, order confirmation and donation receipt that settlement had never sent). and events (upcoming and archive, registration with a waiting list and photography consent asked as a question, and the admin with a door list and a cancellation that tells everybody). and getting involved (volunteer roles and applications with the safeguarding-check workflow that had no screen, and an enquiry-form block for partner, corporate, in-kind and fundraising pages). and the legal pages (a reviewed-from-the-code first draft of all ten policies, seeded as drafts for the trustees to publish). and the responsive pass, which found that rich text had had no typography since Module 1. **Phase 6 is complete.** See `CHANGELOG.md`.

**Phase 7 complete** — projects, causes and impact. Giving levels (“GH₵ 50 provides a school kit”), per-appeal minimums and urgency, goal-reached behaviour, impact measures with disclosure control on anything counting people, a public impact page showing what was raised *and* what was paid out, an aggregated per-appeal expenditure log, and appeal updates that email the donors who funded the work. Peer-to-peer fundraising stays behind `FEATURE_P2P_FUNDRAISING`, off, as an explicit deferral. See `CHANGELOG.md`.

**Phase 8 complete** — donations and Paystack. Landed: the giving flow (frequency chips, direct Mobile Money with the prompt-and-wait page, attribution, the receipt as a PDF behind a signed link, dunning in the tone of a thank-you, a signed management link for regular gifts, and refunds that need two people); and the finance admin (Donations with every action audited, offline gifts, Donors with merge, Regular gifts, Refunds, Webhook events with replay, Reports in integer pesewas, and a test-mode band on every admin page); then the popup checkout (Paystack's window over our page, a setting) and Cloudflare Turnstile on the donation form when its keys are set. See `CHANGELOG.md` and `docs/PHASE-8-PAYMENTS-TEST-PLAN.md`.

**Phase 9 complete** — the shop. Four kinds of product (posted, downloaded, a gift that becomes a receipted donation, a ticket that becomes a code at the door); bulk and signed-in prices; the whole Ghanaian address with the GhanaPost GPS code; a gift at the last step; guest order tracking and signed order links; a customer message on every change of state; invoice and packing-slip PDFs, in bulk; refunds from the order with stock returned when the gateway confirms; and a reports page that adds net shop proceeds to donations without counting a sponsored meal twice. See `CHANGELOG.md`.

**Phase 10 complete** — email and SMS. Landed: the template editors with a live preview inside the real layout and a send-a-test button; a newsletter composer built from blocks, compiled to email-safe HTML and plain text, sent in throttled batches by the cron after a second person approves; topic preferences a subscriber can change with no account; open and click tracking that is off unless switched on and never on receipts; three more SMS gateways (Arkesel, Hubtel, Twilio) chosen in Settings, an SMS broadcast that shows its cost before anybody presses send, and the suppression list as a screen; the failed-jobs list, the outbox and both delivery logs as screens behind the permissions seeded in Phase 3; a worker heartbeat on the health page; and the mail decision — Resend, on a sending subdomain, with its Svix-signed bounce webhook verified for real. See `CHANGELOG.md`, `docs/PHASE-10-EMAIL-DELIVERABILITY.md` and `docs/PHASE-10-SMS-SENDER-ID.md`.

**Phase 11 complete** — engagement. Landed: volunteers after the application (referees the safeguarding checks can take up, shortlist and interview stages with their messages, a Volunteers screen the model never had, hours that a second person verifies, shifts with the evening-before reminder, a thank-you with the hours on leaving, and the safeguarding doc); events (the day-before reminder that was seeded in Phase 3 and never sent, QR-coded tickets on a signed page, a door screen a steward's phone camera opens, and an archive showing what happened); lead capture (an exit-intent newsletter popup that is off until switched on and never on a money page) and contact (SLA reminders that reach a person, offices with hours, WhatsApp and directions); partnerships checked against the brief. See `CHANGELOG.md` and `docs/PHASE-11-SAFEGUARDING.md`.

**Phase 12 complete** — security and compliance. Landed: security headers with a per-request CSP nonce (enforced on the public site, report-only in Filament), the HTML sanitiser that a comment had claimed existed, admin session controls (absolute timeout, single session, IP allowlist, sign out everywhere), a Staff accounts screen at last; payment anomaly alerts and the PCI SAQ-A posture doc; photograph consent that gates publishing and withdraws an image everywhere, export-my-data and delete-my-account with the statutory carve-out, encryption at rest for the safeguarding columns, a cookie notice that gates what comes later, and the Act 843 / GDPR doc with the DPC registration steps; a restore test that actually restores, Sentry, `composer audit` in CI, the Cloudflare setup, the incident runbook and the patch routine. See `CHANGELOG.md` and `docs/PHASE-12-*.md`.

**Phase 13 complete** — SEO, analytics and accessibility. Landed: search & sharing fields with character counts on every content type (the polymorphic table only pages reached before), Article/Product/FAQPage/DonateAction JSON-LD, a sitemap index regenerated on publish, CMS-managed robots extras, self-canonicals on lists, first-touch UTM attribution on donations and orders; a consent-gated analytics provider (none by default) with nine conversion events and the director's own dashboard from the database; an axe audit with four fixes, a structural accessibility check on every commit, and the accessibility statement; the keyword and content plan, local SEO and the Core Web Vitals checklist. See `CHANGELOG.md`, `docs/PHASE-13-SEO-AND-CONTENT.md` and `docs/PHASE-13-ACCESSIBILITY-REPORT.md`.

**Phase 14 complete** — testing and QA. Landed: the Paystack client tested on the wire against a faked HTTP layer, an access matrix that walks every admin URL for every role, the SMS driver contract over all four gateways (and a Twilio bug it found), one slug rule with a test for every model that has one; a browser suite in Chromium — a donation, a purchase, the theme, the keyboard, every public form, axe in both themes — in its own CI job; Larastan at level 5 with a baseline, a coverage job with a floor, three required checks in branch protection; a demo data seeder that refuses production; the manual test plan by journey, browser, theme and breakpoint, the UAT checklist in plain English, load sanity for shared hosting with the k6 scripts, bug triage with issue templates, and `SECURITY.md`. See `CHANGELOG.md` and `docs/PHASE-14-QA.md`.

**Wave 2 complete** — the second phase-two wave. Landed: **beneficiary case management** (the case record encrypted at rest with a blind index for the ID number; every screen generated from one field map with visibility by relationship to the case — worker, Safeguarding Lead, Finance, Auditor; staff-only intake with the signed consent uploaded first; an append-only case log; documents on a private disk behind five-minute signed links; every open, reveal, download and export audited; no delete anywhere); the **accounting export** (a month of the ledger as balanced journal lines against a chart of accounts in the settings, shaped for QuickBooks, Xero, Zoho or a spreadsheet, from a Finance page or the terminal); **multi-currency display** (an approximate dollar, pound or euro figure beside cedi amounts from a daily rate feed or typed rates, chosen by the foundation or the visitor — charging unchanged); **grant management** (funders, the pipeline with deadlines, obligations with weekly reminders to the owner, documents, and spend against each award from the payouts ledger) together with **the payouts screen** the ledger had lacked since Phase 7 (raise, submit, a second person approves, mark paid with the evidence); and **WhatsApp as a fifth channel** (Meta-approved templates, the opt-in on the donate form, receipts and appeal updates, the status webhook — built and off until Meta approves the business, with a launch-check row that says what is missing). See `CHANGELOG.md`.

**Wave 1 complete** — the first phase-two wave, approved from the roadmap. Landed: the site as a **progressive web app** — a manifest built from the settings, home-screen icons rendered from the uploaded logo on the brand colour, a service worker that precaches the shell and never touches anything personal or transactional, and an offline page that shows the Mobile Money number (`FEATURE_PWA_OFFLINE`, now on); the **live thermometer** at `/screen/{appeal}` for a projector — huge total, climbing bar, last five first names honouring anonymity, a QR code to give, refreshed every five seconds from a cached feed, opened from a *Live screen* button on the appeal; the **donor portal** upgrades — *Your impact* (every gift, the appeal updates published after it, the public figures since the first gift, through the same disclosure control as the impact page) and *Receipts* by tax year with the year's total and deductible total and every PDF, including gifts made before the account existed; and the **beneficiary case-management design note** (`docs/DESIGN-BENEFICIARY-CASES.md`) — the data list with visibility per role, the screens, the audit granularity, and nine questions for the safeguarding lead before Wave 2. See `CHANGELOG.md`.

**Phase 18 proposed** — the phase-two roadmap. `docs/ROADMAP.md` scores every item in the brief (PWA, USSD, WhatsApp, the donor portal, an API, grants, beneficiary case management, multi-currency, multilingual, accounting, segmentation, A/B, matching gifts, the live thermometer, a board view, AI drafting) plus the items the phases deferred, against what already exists in the schema and code, and recommends four waves. Nothing is built until the trustees approve a wave.

**Phase 17 complete** — launch. Landed: `scghf:launch-check`, which answers every pre-launch item the application can verify itself (legal pages published, contact details, team, three projects and appeals, shop stocked, a live gift taken and refunded, a live webhook seen, SPF/DKIM/DMARC by DNS lookup, a live SMS delivered, the certificate's expiry by TLS handshake, canonical host, analytics, feature flags honest, two-factor on every staff account, no demo accounts or data); `docs/LAUNCH.md` — pre-launch by area with owners and checks, the go/no-go, the launch-day cut-over with timings, the smoke test, the monitoring window, the first thirty days, the staff training session, the feedback loop, and the prioritised backlog. Found on the way: `FEATURE_PWA_OFFLINE` was on with nothing behind it (now off, on the roadmap) and `FEATURE_SITE_SEARCH` was off while search was live and ungated (now on, and the route honours it). See `CHANGELOG.md`.

**Phase 16 complete** — documentation and handover. Landed: the technical set (`ARCHITECTURE`, `DATABASE` generated from the schema, `DEPLOYMENT` with a pre-deploy dump and a preflight gate, `PAYMENTS` with the state machines and the debugging runbook, `ENVIRONMENT` generated and cross-checked, `SECURITY-MODEL`, `TESTING`, `TROUBLESHOOTING`, `OPERATIONS`, `LICENCES`, `CONTRIBUTING`); the admin manual in plain English with real screenshots (`resources/manual/`, ten chapters and a quick-reference card), shown inside the panel under *Help & manual*; a repeatable screenshot script. Found on the way: a donor's name never showed on the gift page, the test-mode band rendered unstyled, `QUEUE_RETRY_AFTER` was documented but not read, six payment keys were read but not documented. See `CHANGELOG.md`.

**Phase 15 complete** — performance on shared hosting. Landed: a query budget on every public page that found the layout spending 20 queries before any content; one cache generation number that every content save bumps, menus and fragments cached as arrays under it, and a full-page cache for anonymous visitors that swaps the CSP nonce and CSRF token in per hit; a settings-cache bug that had stored `Money` objects since Phase 2, found because the database store refuses objects; visit counting after the response; streamed archives and bounded retention walks; monthly database housekeeping and webhook-payload archiving; the tuned worker line; an inodes row on Site Health; Lighthouse before/after and the VPS path. See `CHANGELOG.md` and `docs/PHASE-15-PERFORMANCE.md`.

> The admin panel is at **`/scghf-office`**, not `/admin` — set by `ADMIN_PATH`.
> Donors sign in at **`/login`**. Staff cannot: see below.

**Hosting: InMotion shared cPanel**, as originally scoped. Everything runs locally without a host, and the pipeline is host-agnostic, so the account details are values rather than code: `SSH_HOST`, `SSH_USER`, `DEPLOY_PATH`, `PHP_BIN`, `APP_URL`.

Four things to confirm on the account itself before the first deploy, because each one is load-bearing rather than a preference:

| Check | Why it is load-bearing |
|---|---|
| **PHP 8.4 selected in MultiPHP Manager** | Not negotiable — Pest 5 needs PHPUnit 13 needs PHP ≥ 8.4.1. See the Phase 2 stack amendment in `CLAUDE.md`. The account already has `ea-php84`; it has to be the *selected* version. |
| **Cron at one-minute resolution** | `schedule:run` must run every minute. The outbox drains on that tick, *frequently and small*, because the mail cap is per hour — a coarser schedule turns an even trickle into bursts, which is what trips shared-hosting rate limiters. |
| **Which image tools the account actually has** | Conversions degrade to whatever is present. `php artisan scghf:media-doctor` reports it against the live account — run it on the server, not locally, because the answer is different there. |
| **Inode headroom** | A media library is the thing that exhausts an inode quota. Each image costs the original plus its conversions; `scghf:media-doctor` prints the current count and what the library is projected to add. |

Runbook steps 7–11 run against this account.

Placeholders are tracked in `docs/PHASE-1-BLUEPRINT.md` §0. Paystack credentials and the SMS sender ID are deliberately placeholdered: the payments module is built and fully tested against a fake gateway, and SMS runs on the `log` driver, until the real accounts exist.

---

*Built in memory of Mrs Cecilia Anyatuik Adam.*
