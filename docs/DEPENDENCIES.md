# Dependencies — why each one is here

Every package is justified, and every one is checked against the constraint that matters most on this project: **InMotion shared cPanel hosting, no root, no Docker, no Redis, no Supervisor, no persistent Node process, and a finite inode budget.**

Where a lighter alternative exists, it is named.

---

## Required

| Package | Why it is here | Lighter alternative? |
|---|---|---|
| **laravel/framework** ^13 | The framework. Locked in `CLAUDE.md`. PHP 8.3+, which the server confirms. | No. |
| **livewire/livewire** ^3 | Server-rendered interactivity for the donation widget, cart, filters and admin forms — without shipping an SPA. On a 3G connection in Ghana, sending HTML fragments beats sending a JSON API plus a React bundle. | Alpine alone for trivial toggles; Livewire earns its place the moment state must be validated server-side, which the donation flow requires. |
| **filament/filament** ^5 | The entire admin panel and CMS. The non-negotiable CMS rule means staff must edit header, footer, menus, pages, sections, theme colours and email templates without touching code — building that by hand is months of work. | Nova (paid), Backpack (paid), or hand-rolled Blade CRUD (far more code, worse). **Filament is the largest dependency here and the one that most affects admin performance — hence PHP-FPM and OPcache in Phase 2 §7.2.** |
| **spatie/laravel-permission** | Eight roles with a capability matrix (Blueprint §7). Permissions are checked, never roles, so the matrix changes without code changes. | Laravel Gates alone — workable but the matrix would live in code rather than the database, which defeats the point. |
| **spatie/laravel-medialibrary** | Uploads, responsive conversions, and the collection API used by every content type. **Also gives us a single place to enforce EXIF/GPS stripping** — non-negotiable given beneficiary photographs of children. | ⚠️ **The riskiest package on shared hosting.** It writes several files per upload, and inode exhaustion (risk SH-6) is a real limit we have not yet measured. Mitigation: cap conversions at 3, use one responsive `srcset` set, and keep the object-storage escape hatch (`FILESYSTEM_DISK`) ready. |
| **spatie/laravel-sluggable** | Human-readable, stable URLs for projects, causes, posts and products. Small, no runtime cost. | Trivial to hand-roll; not worth the time. |
| **spatie/laravel-activitylog** | The admin audit trail — who changed what, field by field. A governance requirement for a registered NGO handling donations, not a nice-to-have. | No sensible alternative. Watch table growth; prune on a schedule. |
| **spatie/laravel-backup** | Scheduled database + media backups written **off-server**. A backup on the same shared account is not a backup. | `mysqldump` via cron — but then you also hand-roll retention, encryption, off-site upload and failure notification. |
| **spatie/laravel-sitemap** | XML sitemaps across projects, causes, posts, events and products. | Hand-rolled Blade view; fine for a small site, tedious once there are five content types with different change frequencies. |
| **spatie/laravel-honeypot** | Spam protection on the contact, newsletter and volunteer forms without a CAPTCHA. **Deliberately not reCAPTCHA:** CAPTCHAs are an accessibility barrier, a third-party data disclosure under Act 843, and an extra round trip on a slow connection. | Hand-rolled honeypot — this package is ~200 lines, but it is well-tested and includes the timing trap. |
| **resend/resend-php** ^1 | The HTTP client behind Laravel's first-party `resend` mail transport, which ships in the framework but needs this package to actually send. Resend is the chosen mail provider (Phase 10, `docs/PHASE-10-EMAIL-DELIVERABILITY.md`): receipts must not leave from the shared cPanel IP. Pure PHP over Guzzle, nothing to install on the server. | Any provider's SMTP relay through the built-in `smtp` mailer — works today, but with no bounce webhook into the suppression list. Postmark needs `symfony/postmark-mailer`, not installed. |
| **sentry/sentry-laravel** ^4 | Error monitoring that reaches whoever maintains the code, with the stack trace and the release, the moment something breaks (Phase 12). Pure PHP over HTTP; nothing to install on the server; a no-op with no DSN. Errors only — tracing sampled at 0, `send_default_pii` off — so no donor data leaves. The in-app error reports (Phase 5) remain for the foundation's staff. | Flare (paid), or the in-app reports alone — which nobody outside the admin panel ever sees. |
| **axe-core** (dev, npm) | The accessibility engine the Phase 13 audit used, kept so the run can be repeated after a redesign (`docs/PHASE-13-ACCESSIBILITY-REPORT.md`). Never shipped. | The browser extension does the same by hand. |
| **laravel/pint** (dev) | One formatting standard, enforced in CI. Removes style from code review entirely. | PHP-CS-Fixer directly; Pint is a thin wrapper with sane Laravel defaults. |
| **larastan/larastan** (dev) | Static analysis at level 5 with a baseline, in CI since Phase 14 (`phpstan.neon.dist`). The brief for Phase 14 asks for it, which resolved the "needs approval" note this row used to carry. Zero production weight. | PHPStan alone — Larastan is PHPStan plus the Laravel knowledge (casts, relations, facades) without which half the errors are noise. |
| **pestphp/pest-plugin-browser** (dev) + **playwright** (dev, npm) | The browser suite (`tests/Browser`): a donation, a purchase, the theme, the keyboard and every public form in Chromium, on every pull request. The app is served in-process, so the test's database and fake gateway are the browser's. Never shipped; the deploy gate does not run it. | Laravel Dusk — needs ChromeDriver and a separate server; the Pest plugin needs neither. |
| **fakerphp/faker** | Every model factory, and through them `DemoDataSeeder`, which loads staging with a believable foundation for testers (`DEPLOYMENT.md` §7). Laravel ships it as a dev dependency; here it is a runtime one because the release is built `--no-dev` and the seeder must run on staging. Small, no side effects; the seeder itself refuses production. Moved 2026-09-19 when the first staging seed failed on `fake()`. | Hand-written fixtures — thousands of lines for what factories express in one. |
| **pestphp/pest** (dev) | The test runner. `CLAUDE.md` requires tests for anything touching money, auth or webhooks. | PHPUnit — Pest sits on top of it, so this is a syntax preference, not a capability one. |

---

## Deliberately **not** installed

| Package | Why not |
|---|---|
| **Laravel Horizon** | Requires Redis. Not available. We use the database queue driver with a cron-launched worker. |
| **Laravel Telescope** | Heavy request logging, large tables, and a production data-exposure risk. Use it locally if needed, never on the server. |
| **Laravel Scout + Meilisearch/Algolia** | Needs a persistent daemon or a paid third party. Site search is deferred post-launch (Blueprint §3.2); when it lands, MySQL full-text will be enough for <500 content items. |
| **Predis / phpredis** | No Redis on this plan. |
| **Laravel Octane** | Needs Swoole/RoadRunner and a persistent process. Impossible here. |
| **Intervention Image (standalone)** | Media Library already wraps an image driver. Adding a second is duplicated dependency weight. |
| **Laravel Cashier** | Stripe/Paddle-oriented. Paystack subscriptions are handled directly through `PaystackService`. |
| **A JS framework (React/Vue/Inertia)** | Blade + Livewire + Alpine meets the requirement at a fraction of the bundle size. The CI asset budget is 100 KB of gzipped JS; a React app blows that before any application code. |
| **Google reCAPTCHA / hCaptcha** | See honeypot above — accessibility, Act 843, and latency. |
| **A paid APM (New Relic, Datadog)** | No agent installation on shared hosting. Error tracking uses Sentry's or Flare's SDK instead. |

---

## Under review

| Package | Decision needed |
|---|---|
| **barryvdh/laravel-dompdf** *or* **spatie/laravel-pdf** | Needed in Phase 8 for donation receipts. `dompdf` is pure PHP and works anywhere. `spatie/laravel-pdf` produces far better output but drives headless Chromium — **impossible on shared hosting.** Recommendation: dompdf. Decide in Phase 8. |
| **propaganistas/laravel-phone** | E.164 normalisation for Ghanaian numbers (risk DEL-9). Wraps giggsey/libphonenumber, which is a few MB. Alternative: a ~40-line cast handling the `024…`/`+233…`/`233…` forms ourselves. Recommendation: hand-roll it — we only need one country. |

---

## Adding a dependency later

Before running `composer require`, check:

1. Does it need Docker, root, a daemon, Redis, or a persistent Node process? → **stop**, propose a shared-hosting alternative first.
2. Does it write many files per operation? → check it against the inode budget.
3. Is it dev-only? → `--dev`, so it never ships to the server.
4. Does it add a third-party network call on a user-facing request path? → that is a latency and an Act 843 disclosure question, not just a technical one.
5. Is it actively maintained, and does it support the current Laravel major?

Then record the justification in this file in the same PR.
