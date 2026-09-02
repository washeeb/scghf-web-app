# CLAUDE.md — Foundation Web App

This file is the persistent project context. Read it in full before doing any work on
this repository. It reflects real decisions already made for this project — do not
re-litigate them without asking.

---

## What this project is

A production-grade, dynamic, fully functional web application for a Ghanaian
foundation (non-profit). It raises funds through donations and an online shop, and
showcases the foundation's projects and causes. Treat this as a real client project,
not a demo or prototype.

Core capabilities:
- Accepts donations (one-off and recurring) via Paystack, in Ghanaian Cedis (GHS).
- Sells items in an online shop to raise funds, also via Paystack.
- Showcases projects, causes/campaigns, and impact.
- Fully CMS-driven — the foundation's non-technical staff must be able to edit
  header, footer, menus, pages, homepage sections, and all content without touching
  code.
- Sends email and SMS notifications (donation receipts, order updates, campaigns).
- Supports light and dark themes.
- Deploys from GitHub to InMotion Hosting shared cPanel hosting.

---

## Stack (locked — do not substitute without discussion)

- **Framework:** Laravel 13 (v13.30.1), **PHP 8.4+** (confirm exact PHP version against the target
  cPanel's PHP Selector/MultiPHP Manager before assuming a minor version).
- **Database:** MySQL 8 / MariaDB, created via cPanel's "MySQL Databases" tool.
- **Frontend:** Blade + **Livewire 4** + Alpine.js + Tailwind CSS. Vite for asset
  building.
- **Admin panel / CMS:** Filament v5 (v5.7.8 — requires Livewire 4, not 3).
- **Payments:** Paystack — donations and shop orders both, GHS currency.
- **Hosting:** InMotion Hosting shared cPanel hosting. No Docker, no root access, no
  Redis, no Supervisor, no persistent Node process on the server.
- **Key packages:** spatie/laravel-permission, spatie/laravel-medialibrary,
  spatie/laravel-sluggable, spatie/laravel-activitylog, spatie/laravel-backup,
  spatie/laravel-sitemap, spatie/laravel-honeypot, laravel/pint, pestphp/pest.

Do not introduce a dependency that requires infrastructure InMotion shared hosting
cannot provide (Docker, root, a persistent daemon, Redis/Memcached unless confirmed
available) without flagging it first and proposing a shared-hosting-safe alternative.

### Stack amendments — Phase 2, 2026-09-02

Two entries above were changed during Phase 2. Both were forced by dependency
resolution, not preference, and both are recorded here rather than silently applied.

- **PHP 8.3 → 8.4.** On 8.3, Pest is not installable at all (pest-plugin-laravel v5
  needs PHP ^8.4; Pest 4 conflicts with the PHPUnit 12.5 Laravel 13 ships; Pest 5
  needs PHPUnit 13, which needs PHP ≥ 8.4.1), and spatie/laravel-sitemap has no
  version compatible with both PHP 8.3 and the Guzzle 8 Laravel 13 ships. Since
  Pest and its tests are mandatory here, 8.3 was not viable. The server already has
  `ea-php84`. Composer pins `config.platform.php` to `8.4.1`; both CI workflows run 8.4.
- **Livewire 3 → 4.** Filament v5 requires Livewire 4. "Filament v5" and "Livewire 3"
  could not both hold. Filament is the harder constraint, so Livewire 4 it is —
  which means Phase 4 onward must use Livewire 4 idioms, not Livewire 3 ones.

Installed and verified: Laravel 13.30.1 · Filament 5.7.8 · Livewire 4.4.3 ·
Pest 5.1.3 · PHPUnit 13.3.1 · spatie/laravel-sitemap 8.2.0.

---

## Hosting & deployment constraints

- Code lives in a private GitHub repository. Pushing to `main` deploys to production.
- Laravel's `public/` directory maps to `public_html`; application code sits outside
  the web root.
- Deployment is either cPanel Git Version Control with a `.cpanel.yml`, or GitHub
  Actions deploying over SSH/rsync — chosen based on what the actual cPanel account
  supports (check for SSH, Composer, Node, cron availability first).
- The scheduler and queue run from **cPanel cron jobs**, not Supervisor:
  ```
  * * * * * php /home/CPANELUSER/app/artisan schedule:run >> /dev/null 2>&1
  * * * * * php /home/CPANELUSER/app/artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
  ```
- A staging subdomain exists with its own database and `.env`, deployed from
  `develop`, using Paystack test keys, and noindexed.
- Secrets never enter the repository. `.env.example` documents every key with a safe
  placeholder; real values live only in the server's `.env`.

---

## Money & Paystack rules (critical — get this wrong and money is lost or miscounted)

- All monetary amounts are stored as **integer pesewas** (GHS × 100), never floats.
  GH₵ 50.00 → `5000`.
- Every amount sent to Paystack includes `"currency": "GHS"`.
- Display format: `GH₵ 1,234.56`.
- Payment truth comes from the **webhook**, never the browser redirect:
  1. Read the raw request body.
  2. Compute `hash_hmac('sha512', $rawBody, $secretKey)`.
  3. Compare to the `x-paystack-signature` header with `hash_equals()` (timing-safe).
  4. Reject on mismatch.
  5. Store the raw event in `payment_webhook_events` before processing.
  6. Respond `200` immediately; process the event on the queue.
  7. Processing is idempotent — replaying the same event must never double-count a
     donation or order.
- Re-verify amount and currency from the webhook against what we expected before
  marking a donation/order complete. A mismatch raises an alert, not an
  auto-complete.
- All Paystack calls go through a single `PaystackService` — never call the API
  directly from a controller. One transaction table and one webhook handler for both
  donations and shop orders (polymorphic payable).
- Card data is never touched, logged, or stored — only Paystack authorization codes.

---

## CMS rule (non-negotiable)

No hardcoded content in Blade templates: no strings, phone numbers, emails,
addresses, colours, or images. Everything content-related — header, footer, menus,
pages, homepage sections, banners, SEO metadata, email/SMS templates, theme colours —
comes from the database via the settings/CMS layer, editable in Filament, with
seeded defaults. If you find yourself hardcoding a piece of real content, stop and
move it into the CMS layer instead.

---

## Design

- Full light and dark theme, toggle with light/dark/system states, no flash of
  wrong theme on load (inline head script), persisted in localStorage + cookie.
- Both themes must meet WCAG 2.2 AA contrast — check every component in both, not
  just an automatic inversion.
- Mobile-first. Must perform well on low-end Android devices and slow connections
  (the real usage context in Ghana). LCP target under 2.5s on simulated 3G.
- Brand tokens (colours, type scale, spacing, logos) come from the foundation's
  uploaded profile and logo pack — not invented.

---

## Quality bar ("done" includes all of this, not just working code)

- WCAG 2.2 AA accessibility.
- OWASP Top 10 hardening; PCI DSS SAQ-A posture (we never touch card data).
- SEO: structured data, sitemaps, per-entity meta fields, Core Web Vitals budget.
- Automated tests (Pest) for anything involving money, auth, or webhooks — required,
  not optional.
- Ghana Data Protection Act, 2012 (Act 843) compliance; consent tracking for donor
  data and for photographs of beneficiaries (especially children).
- Documentation kept current: `README.md`, `CHANGELOG.md`, and the admin user manual.

---

## How to work in this repo

- Work in the phases defined in `FOUNDATION-WEBAPP-MASTER-PROMPT.md`. Complete one
  phase fully, including tests and docs, before starting the next.
- Before writing code for a new piece of work, restate the plan in 3–5 bullets and
  list assumptions.
- Produce complete, runnable files — never fragments or "// rest of code here".
- Show migrations, models, controllers/actions, form requests, policies, Livewire
  components, Filament resources, Blade views, routes, and tests as separate,
  clearly labelled files with full paths.
- Give exact terminal commands, in order, for anything that needs to run.
- Flag anything that will not work on shared hosting and propose the shared-hosting
  alternative before implementing.
- Keep `CHANGELOG.md` and `README.md` up to date as work lands.
- Never commit secrets. `.env.example` must document every new key added to `.env`.

### End-of-task checklist

After finishing any unit of work, check:
1. Anything the user missed, forgot, or under-specified for this task.
2. Anything worth adding to make this genuinely production-ready.
3. Any decision needed from the user before continuing.
4. What is now testable, and how to verify it.

---

## Reference: things commonly forgotten on projects like this

Donation receipts as PDFs · a "General Fund" fallback cause so donations always have
a destination · an option to cover the Paystack transaction fee · offline/cash
donation recording · daily reconciliation against Paystack settlements · a refund
policy page · consent capture for beneficiary photographs · EXIF/GPS stripping on
uploaded images · SMS sender-ID registration (unregistered sender IDs get blocked in
Ghana) · SPF/DKIM/DMARC records for email deliverability · a backup restore that has
actually been tested, not just backups that run · cPanel inode limits on the media
library · staging kept noindexed · `APP_DEBUG=false` and `APP_ENV=production` on the
live server · a tested rollback plan · 2FA enforced on every admin account · a
404-to-redirect workflow · dark-mode review of every component individually, not just
an automatic colour inversion · performance on low-bandwidth connections · confirming
who owns the domain and the Paystack account.

---

*Companion document: `FOUNDATION-WEBAPP-MASTER-PROMPT.md` contains the full phase-by-
phase build prompts (Phase 0 through Phase 18). This file is the standing context;
that file is the sequence of work.*
