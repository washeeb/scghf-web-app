# Launch runbook — before, the day, and the first thirty days

The site is built, tested and documented. This is the sequence that turns
it into the foundation's live website, with an owner and a way to check
each step. Two commands carry most of the checking:

```bash
php artisan scghf:launch-check       # everything the application can verify itself: exit 0 = nothing it can see stands in the way
```

```bash
php artisan scghf:preflight          # the installation only (part of the above); the deploy script runs it
```

Owners used below: **Dir** (the director / site owner), **Tre** (treasurer),
**Dev** (developer), **Sfg** (safeguarding lead), **Off** (the office). Fill
the names in `OPERATIONS.md` §4.

## 1. Pre-launch

Work down in order. A row with a **Check** column is answered by
`scghf:launch-check` (LC) or *Site Health* (SH); the rest are a person.

### 1.1 Content

| # | Step | Owner | Check |
|---|---|---|---|
| C1 | Every setting placeholder replaced: organisation, contact, social, banking, consent wording (*Website → Site settings*) | Off | `scghf:preflight` lists what is still `{{…}}`; LC *Contact details* |
| C2 | The ten legal pages reviewed by the trustees and **published** (privacy, terms, donation, refund, shipping, cookie, safeguarding, accessibility, whistleblowing, anti-fraud) | Dir | LC *Legal pages published* |
| C3 | Team and board in *Content → Team* with photographs — and a consent record on each photograph | Dir, Sfg | LC *Team and board* |
| C4 | At least three real projects and three real appeals published, with images (with consent) and a real goal; the General Fund published | Dir | LC *Projects and appeals live* |
| C5 | Shop stocked, or switched off (`FEATURE_SHOP=false`) | Off | LC *Shop stocked* |
| C6 | The home page walked through in both themes on a phone; every menu link goes somewhere | Off | manual §3.2 J7, J14 |
| C7 | The About/Story/Values pages have the foundation's own text, not the seeded defaults | Dir | read them |
| C8 | Search & sharing filled on the ten pages that matter (`PHASE-13-SEO-AND-CONTENT.md` §5) | Off | look at each with the Facebook/WhatsApp link preview |

### 1.2 Paystack

| # | Step | Owner | Check |
|---|---|---|---|
| P1 | Business verified with Paystack; **settlement account confirmed as the foundation's** and the settlement schedule read (T+1 for GHS cards; MoMo varies) | Tre | Paystack dashboard → Settings → Business |
| P2 | Live keys into `shared/.env` on the **production** server only: `PAYMENT_DRIVER=paystack`, `PAYSTACK_PUBLIC_KEY=pk_live_…`, `PAYSTACK_SECRET_KEY=sk_live_…`, `PAYSTACK_WEBHOOK_SECRET=` the same secret key; `php artisan config:cache` | Dev | SH *Paystack* says **Live keys**; the test-mode band is gone |
| P3 | Live webhook URL registered in the dashboard's **Live mode**: `https://<site>/webhooks/paystack` | Dev | LC *Live webhook received* once P4 is done |
| P4 | **One GH₵ 1.00 gift** from a trustee's own card; watch it: *Finance → Webhook events* row within seconds, gift *Completed*, receipt email received with the PDF | Tre | LC *A live gift taken* |
| P5 | **Refund it** through the two-person flow the same day; `refund.processed` arrives; gift reads *Refunded* | Tre + a second finance user | LC *…and refunded* |
| P6 | Record P4–P5 in `PHASE-8-PAYMENTS-TEST-PLAN.md` §5 | Tre | the row exists |
| P7 | A test **Mobile Money** gift on the live keys from a real MTN number, GH₵ 1, refunded | Tre | the gift's page shows `channel: mobile_money` |
| P8 | `scghf:reconcile-payments` run by hand once; *needs review* is zero | Tre | *Finance → Reports* |

### 1.3 Email

| # | Step | Owner | Check |
|---|---|---|---|
| E1 | Sending subdomain in Resend, its SPF, DKIM and DMARC records in DNS (`PHASE-10-EMAIL-DELIVERABILITY.md`) | Dev | LC *Mail domain: SPF, DKIM, DMARC* — run **on the server** |
| E2 | `MAIL_MAILER=resend`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` on the subdomain, the bounce webhook secret | Dev | SH *Email sending* |
| E3 | **Send a test receipt to a Gmail, a Yahoo and an Outlook address** (*Communications → Email templates → the receipt → Send me a test*). Each lands in the **inbox**, not spam; the PDF opens | Off | three inboxes |
| E4 | mail-tester.com score 9+ on one of those sends | Dev | the score |
| E5 | The alerts address (*Settings → Email & SMS*) is a real inbox somebody reads | Dir | a test alert arrives |

### 1.4 SMS

| # | Step | Owner | Check |
|---|---|---|---|
| S1 | Sender ID (`SMS_SENDER_ID`, 11 characters) **approved** by the provider for MTN, Telecel and AT (`PHASE-10-SMS-SENDER-ID.md`) | Off | provider dashboard |
| S2 | Provider chosen in *Settings → Email & SMS*, key in `.env`, credits loaded | Dev, Off | SH *SMS credits* |
| S3 | A live test text to a phone on **each** network from *SMS templates → Send me a test*; the sender shows as the foundation's ID | Off | LC *A live SMS delivered*; the phones |

### 1.5 Domain and SSL

| # | Step | Owner | Check |
|---|---|---|---|
| D1 | Decide **www or bare** as canonical; `APP_URL` set to it; the other redirects in `.htaccess` (`DEPLOYMENT.md`) | Dir, Dev | LC *One canonical host*; `curl -I` the other returns 301 |
| D2 | DNS A/CNAME at the registrar → the server (or Cloudflare, `PHASE-12-INFRASTRUCTURE.md` §2); TTL lowered to 300 the day before | Dev | `dig`/the browser |
| D3 | AutoSSL issued for both hosts; HTTPS forced; HSTS on (`HSTS_MAX_AGE` 6 months, then a year after a fortnight) | Dev | LC *Certificate*; SH *Secure connection*; `https://www.ssllabs.com/ssltest/` A |
| D4 | `TRUSTED_PROXIES` set if Cloudflare is in front | Dev | the visitor IP in *login_histories* is not Cloudflare's |

### 1.6 Search and analytics

| # | Step | Owner | Check |
|---|---|---|---|
| G1 | *Settings → Search engines → Allow indexing* **on** for production; staging stays off | Dev | SH *Search engines*; `/robots.txt` |
| G2 | Google Search Console: domain property verified (DNS TXT), `sitemap.xml` submitted; Bing imported from it | Dev | the property shows the sitemap read |
| G3 | Analytics provider chosen or *none* recorded as the decision (`PHASE-13-SEO-AND-CONTENT.md` §5); if chosen, a visit shows up after consent | Dir, Dev | LC *Analytics chosen*; the provider's dashboard |
| G4 | Google Business Profile claimed | Off | `PHASE-13-SEO-AND-CONTENT.md` §3 |

### 1.7 Backups and cron

| # | Step | Owner | Check |
|---|---|---|---|
| B1 | Off-server backup destination configured, `BACKUP_ARCHIVE_PASSWORD` set **and written in the office safe** | Dev, Dir | SH *Backups* after the first night |
| B2 | **First backup taken** by hand: `php artisan backup:run` on production | Dev | the archive at the destination |
| B3 | **Restore rehearsed** on staging: `php artisan scghf:restore-test` (and once, a full restore of production's backup into staging's database, signed in, a gift opened) | Dev | SH *Restore test*; `PHASE-12-INFRASTRUCTURE.md` §1 |
| B4 | Both cron lines in cPanel for production (`deploy/cpanel/cron.txt`); heartbeats green | Dev | SH *Scheduled jobs*, *Background queue* |

### 1.8 Security

| # | Step | Owner | Check |
|---|---|---|---|
| X1 | `APP_ENV=production`, `APP_DEBUG=false` (the deploy script refuses otherwise) | Dev | SH *Environment* |
| X2 | **No demo accounts, no demo data** in the production database — it was migrated and seeded with `DatabaseSeeder` only | Dev | LC *No demo accounts*, *No demo data* |
| X3 | Every staff account has signed in once and enrolled a second factor; leavers suspended | Dir | LC *Two-factor on every staff account* |
| X4 | `ADMIN_PATH` is not `admin`; `ADMIN_IP_ALLOWLIST` decided (the office, or empty) | Dev | SH; sign in from a phone on mobile data if empty |
| X5 | Error pages: `/nothing-here` shows the site's 404, a forced error the site's 500 — not a stack trace | Dev | `APP_DEBUG=false` and look |
| X6 | Final external scan: Mozilla Observatory A, securityheaders.com A, ssllabs A | Dev | the three reports |
| X6a | Every database table is InnoDB — InMotion's MariaDB defaults to MyISAM (no transactions, no foreign keys); the connection pins InnoDB, this catches hand-made tables | Dev | LC *Every table on InnoDB* |
| X7 | Feature flags honest: nothing on that is not built | Dev | LC *Feature flags honest* |
| X7a | WhatsApp: off, or on with Meta's credentials, `WHATSAPP_DRIVER=cloud` and an approved template | Dev + Comms | LC *WhatsApp ready if on* |
| X8 | Staging `seo.allow_indexing` off; `robots.txt` on staging says Disallow | Dev | fetch it |
| X9 | `PREFLIGHT_GATE=1` on the production GitHub environment after the first deploy | Dev | `DEPLOYMENT.md` §5 |

### 1.9 Performance, accessibility, browsers

| # | Step | Owner | Check |
|---|---|---|---|
| Q1 | Lighthouse mobile on production's home, donate, an appeal: Performance ≥ 90; fill the table in `PHASE-15-PERFORMANCE.md` §4.3 | Dev | the table |
| Q2 | axe (browser extension) on the same pages in both themes: zero critical | Dev | the extension |
| Q3 | The manual matrix `PHASE-14-QA.md` §3.1 — the ● cells — signed off, on a real low-end Android on MTN data | Off | the matrix, initialled |
| Q4 | The UAT checklist `PHASE-14-QA.md` §4 walked on staging with the demo data by two staff who did not build it | Off | every line ticked or an issue filed |

### 1.10 Rollback, tested

| # | Step | Owner | Check |
|---|---|---|---|
| R1 | On staging: deploy, then `rollback.sh`, then deploy again — the site works after each | Dev | `DEPLOYMENT.md` §4 |
| R2 | On staging: restore the pre-deploy dump over the database and confirm the site still signs in | Dev | same |
| R3 | The previous host's site (if any) kept reachable for 30 days under another name | Dev | it answers |

**Go / no-go:** `scghf:launch-check` exits 0 on production, every row above
is initialled, the trustees have signed the legal pages. If not all
three: not today.

## 2. Launch day

Choose a **Tuesday to Thursday, 09:00–10:00 Ghana time**: everybody is at
work, Paystack and the host have full support, and there are two working
days before a weekend. Not the first of the month (the maintenance runs),
not a campaign day.

| Time | Step | Owner | Done when |
|---|---|---|---|
| T−1 day | DNS TTL to 300 s; `scghf:launch-check` green on production; announcement drafted for the site and social; the office copy of the manual's quick-reference card filled in | Dev, Off | all four |
| T−1 day | Backup of production by hand; `previous_release` noted | Dev | the archive exists |
| **T 09:00** | **Go/no-go call** (Dir, Tre, Dev, Off): the three conditions in §1 | Dir | "go" said out loud |
| 09:05 | Final `scghf:launch-check` and `scghf:preflight` on production | Dev | exit 0 |
| 09:10 | **DNS**: point the domain at production (or flip the Cloudflare proxy on) | Dev | `dig` from two networks shows the new address |
| 09:15 | `curl -I https://<site>/` from mobile data: 200, HTTPS, HSTS header, canonical host redirect works | Dev | four yes |
| 09:20 | **Smoke test** (§2.1), everybody on their own phone | all | the list ticked |
| 09:40 | `seo.allow_indexing` on (if it was left off for the switch); Search Console *Request indexing* on the home page | Dev | `/robots.txt` allows |
| 09:45 | Announcement bar on for the week ("We have a new website — tell us what you think", linking to the contact page) | Off | it shows |
| 10:00 | Post the announcement (social, WhatsApp groups, the partner list) — **after** the smoke test, never before | Dir | posted |
| 10:00–13:00 | **Monitoring window** (§2.2): one person watches; nobody deploys | Dev | the window ends with no red |
| 13:00 | Review call: anything odd; decide whether to keep watching to 17:00 | Dir, Dev | notes in the incident log |
| T+1 09:00 | `scghf:reconcile-payments` by hand; every gift from day one matched; first nightly backup arrived | Tre, Dev | SH green; *needs review* zero |
| T+7 | DNS TTL back to 3600; HSTS to a year; `PREFLIGHT_GATE=1` | Dev | done |

### 2.1 Smoke test (twenty minutes, on phones)

- [ ] Home in light and dark; a project; an appeal; the news page; the FAQ
- [ ] `/donate`: a **GH₵ 1 gift by card** (a trustee) → thank-you page → receipt email with PDF → *Finance → Donations* shows it → **refund it** (two people) — the same day
- [ ] A **GH₵ 1 MoMo gift** the same way
- [ ] The shop: add to basket, checkout to the payment page (stop there, or buy the cheapest item and mark it collected)
- [ ] Contact form → acknowledgement email → the message in *Inbox*
- [ ] Newsletter signup → confirmation email → confirm
- [ ] Sign in to the admin from a phone; dashboard; *Site Health* all green
- [ ] `https://<site>/up` returns 200; the old address (if any) redirects
- [ ] The 404 page; the sitemap at `/sitemap.xml`; `/robots.txt`
- [ ] One admin edit (the announcement bar) appears on the site within a reload

### 2.2 Monitoring window (the first four hours, then the first week)

Watch, in this order, every 30 minutes:

1. UptimeRobot — no alert.
2. Sentry — no new issue (a known one that recurs is fine; a new one is read).
3. *Site Health* — every row green; *Background queue* not stale.
4. *Finance → Webhook events* — every event signature-valid and processed.
5. *Finance → Donations* — nothing *needs review*.
6. *Communications → Email log* — every receipt *delivered*, none bounced.
7. cPanel → *Resource Usage* — no entry-process or CPU faults.
8. The contact inbox and the social posts — what people are saying.

Stop conditions (roll back or put the announcement bar up, then fix): a
payment that Paystack shows and the site does not within five minutes;
`/up` failing; receipts bouncing; anything under Finance red.

## 3. Post-launch — the first thirty days

| When | What | Owner |
|---|---|---|
| **Daily** | `scghf:reconcile-payments` output read (it runs at 06:30; the treasurer reads the *Reports* page and the anomaly emails): every Paystack settlement matched, *needs review* zero, settlement amounts in the bank matching the dashboard | Tre |
| Daily | Sentry and UptimeRobot reviewed; `failed_jobs` empty | Dev |
| Daily | Contact inbox within its SLA; new subscribers confirmed; first orders dispatched | Off |
| Day 3 | **Staff training session** (§3.1) | Dev + Dir |
| Weekly | Search Console: coverage (pages indexed vs the sitemap), errors, rich results valid; Core Web Vitals once field data appears (28 days) | Dev |
| Weekly | *Site analytics*: visits, the donate page's conversion, the referrers — a first baseline | Dir |
| Weekly | The **feedback loop** (§3.2) reviewed; issues filed; S1/S2 fixed that week | Dir, Dev |
| Day 14 | Lighthouse and axe again on production; the numbers into `PHASE-15-PERFORMANCE.md` §4.3 | Dev |
| Day 30 | **Review**: what went wrong, what was slow, what nobody used; the backlog (§3.3) prioritised for Phase 18 | Dir, Tre, Dev, Off |
| Day 30 | First monthly maintenance run watched (`OPERATIONS.md` §2) | Dev |

### 3.1 Staff training session (two hours, at the office, on the live site)

1. Everyone signs in on their own laptop and enrols their authenticator (manual ch. 1). 20 min.
2. The dashboard and *Site Health*: what green and red mean. 10 min.
3. Each person does **their** job once, with the manual open: the office records a cash gift and resends the receipt (ch. 5); the editor publishes a post and changes the home hero (ch. 2–3); the shop manager dispatches an order (ch. 6); the treasurer runs reconciliation and requests a refund the director approves (ch. 5). 60 min.
4. *What not to touch* (ch. 9) read aloud. 10 min.
5. Everyone bookmarks *Help & manual* and takes a printed quick-reference card with the names filled in. 10 min.
6. A second, shorter session after two weeks for the questions that came up.

### 3.2 The feedback loop

- Staff: a standing note in the office ("what was confusing this week"), read at the weekly review; anything real becomes a UAT-finding issue.
- Donors and visitors: the contact form (*General enquiry* department, watched in *Inbox*), and the announcement bar's "tell us what you think" link pointing at it for the first month.
- Numbers: the donate page's completion rate in *Site analytics* — a fall after a change is the signal that matters.
- Everything goes into GitHub issues with a severity (`PHASE-14-QA.md` §6); the weekly review decides.

### 3.3 The backlog — everything deferred, in priority order

| Priority | Item | Where it came from | Why this position |
|---|---|---|---|
| 1 | **Cloudflare in front** (DNS proxy, edge cache, WAF free tier) | Phase 12, 15 | the largest speed and safety win available, one DNS change |
| 2 | **Set `COVERAGE_MIN` and `MEDIA_INODE_BUDGET`** from the first real numbers; `PREFLIGHT_GATE=1` | Phase 14, 15, 16 | ten minutes; makes three gates real |
| 3 | **Paystack staging run recorded**; a second Mobile Money network tested | Phase 8 §5 | the run record is empty |
| 4 | **PWA: offline page, manifest, add-to-home-screen** (`FEATURE_PWA_OFFLINE` off) | Phase 1, 17 | the flag exists; a real gain on flaky connections |
| 5 | **IndexNow** ping on publish | Phase 13 | once publishing is weekly |
| 6 | **Event ticketing** through the shop (`FEATURE_EVENT_TICKETING` off) | Phase 1 §12c | when a paid event is planned |
| 7 | **Peer-to-peer fundraising** pages (`FEATURE_P2P_FUNDRAISING` off; the `fundraisers` table exists) | Phase 7 | a campaign that wants it |
| 8 | **A second language** (`FEATURE_MULTILINGUAL` off; strings are `__()`) | Phase 1 | Twi first, if donors ask |
| 9 | **WhatsApp receipts and updates** | Phase 18 list | donors' preferred channel; needs the Business API |
| 10 | **Object storage for media** (S3/R2) | Phase 15 §5 | when inodes pass 70 %, or at the VPS move |
| 11 | **VPS migration** | Phase 15 §5 | on the signs in that section, not before |
| 12 | **Accounting export** (Xero/QuickBooks CSV first, API later) | Phase 18 list | when the treasurer asks; the CSV export covers today |
| 13 | **Lapsed-donor win-back** and segmentation | Phase 18 list | needs six months of giving data |
| 14 | **DemoDataSeeder teardown** for staging | Phase 14 | staging is re-seeded; low |
| 15 | **A/B testing on the donate form** | Phase 18 list | needs traffic to mean anything |

Phase 18 proposes the roadmap for items 4–15 properly, with value, effort,
risk and dependencies.

## 4. If launch day goes wrong

- The site is up but a payment did not record → `PAYMENTS.md` §6, in order; do not announce until it is understood.
- Receipts bounce → E1–E3; the bar says "receipts are delayed"; nothing else stops.
- The site is down after DNS → point DNS back (TTL is 300, so five minutes), or the Cloudflare proxy off; then `TROUBLESHOOTING.md` #1–#7.
- A bad deploy → `DEPLOYMENT.md` §4; the decision to roll back is the developer's alone and needs no call.
- Anything involving personal data seen by the wrong person → `SECURITY-MODEL.md` §5, then the announcement waits.
