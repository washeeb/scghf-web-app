# Architecture — the map, the request, the services, and why

For a developer who has never seen this repository. The phase documents
(`PHASE-*.md`) are the record of how each part was built and are linked
from here rather than repeated.

## 1. In one paragraph

A Laravel 13 monolith. The public site is server-rendered Blade with one
6 KB JavaScript module and no Livewire; the admin panel is Filament 5
(Livewire 4) at a configurable path. MySQL holds everything (135 tables,
`DATABASE.md`). There is no Redis and no daemon: caches are files and a
`cache` table, the queue is a `jobs` table drained by a cron worker, the
scheduler runs from one cron line. Paystack takes the money; the
application believes only the signed webhook. Every piece of content —
menus, pages, blocks, theme colours, templates — is data in the CMS with
seeded defaults; Blade contains no strings that belong to the foundation.

## 2. Module map

```
app/
├── Http/            33 controllers (public site, accounts, webhooks), 9 middleware, form requests
├── Filament/        the admin panel: 51 resources, 6 pages, relation managers, Support/ (MediaPicker, SeoFields, ExportAction)
├── Models/          119 Eloquent models, Concerns/ (HasSeo, RecordsAuthor, BelongsToDivision, DeIdentifiable…)
├── Policies/        60 policies; BasePolicy maps abilities onto `prefix.ability` permissions, deny by default
├── Payments/        PaystackService, FakeGateway, PaymentManager, Donation/Offline/Recurring/Refund/Reconciliation services, receipts
├── Shop/            CheckoutService, CurrentCart, OrderFulfilment, InvoiceIssuer, TicketIssuer, RegulatoryScreener, ShopReports
├── Communications/  MessageDispatcher, TemplateRenderer, CampaignSender, BroadcastSender, four SMS gateways, PhoneNumber, tracking
├── Community/       volunteers, events, enquiries: notifiers, TicketQr
├── Programmes/      cause updates to donors
├── Privacy/         AccountDataExporter, AccountEraser (Act 843 / GDPR)
├── Media/           MediaLibrary, ImageToolchain, UploadPolicy, MediaUsage, the EXIF sanitiser
├── Blocks/          BlockRegistry, BlockDefinition, BlockDataResolver — the page builder's vocabulary
├── Support/         Settings, ThemeTokens, SiteCache, PageMeta, StructuredData, Html (sanitiser), SiteHealth, Attribution, Analytics…
├── Console/Commands 26 scghf:* commands (the scheduler's work and the operator's tools)
├── Jobs/            ProcessPaymentWebhook, ProcessDeliveryWebhook
├── Observers/       SitemapObserver, SiteCacheObserver
├── Enums/ Casts/ ValueObjects/ (Money) Rules/ Contracts/ Events/ Listeners/ Mail/
└── Providers/       App, Auth, Communication, Payment, Filament/AdminPanelProvider

config/       admin, communications, compliance, features, media, payments, performance, security, system + Laravel's
database/     migrations (chronological, Phase 3 onward), factories, seeders (DatabaseSeeder = every deploy; DemoDataSeeder = staging only)
resources/    views/ (components/site, components/layouts, blocks/, pages), css/app.css (Tailwind v4), js/ (theme, navigation, consent, analytics, popup, announcement)
routes/       web.php (public + account + webhooks), console.php (the schedule)
deploy/       activate.sh, rollback.sh, bootstrap-server.sh, cpanel/cron.txt
tests/        Unit/, Feature/ (~1,900), Browser/ (Playwright)
docs/         this folder; manual/ for staff
```

## 3. The request lifecycle

For a public page — say `/appeals/back-to-school-2026` — in order:

1. **Apache** (`public/.htaccess`): HTTPS redirect, compression, cache
   headers for static files, the security headers duplicated for files PHP
   never sees, everything else to `index.php`.
2. **`bootstrap/app.php`** builds the `web` middleware group:
   `SecurityHeaders` (prepended: generates the per-request CSP nonce,
   sets every header) → Laravel's session/cookie/CSRF stack (cookies
   encrypted except the two the browser writes) → `CaptureAttribution`
   (first-touch `utm_*` into the session) → `CountVisit` (decides what to
   count; writes in `terminate()`) → **`CachePublicPage`** (serves a stored
   page to an anonymous visitor with the nonce and CSRF token swapped in,
   or stores the one about to be rendered) → `HandleRedirects` (only on a
   404) → `SetRobotsHeader`.
3. **Routing** (`routes/web.php`): route-model binding by slug or ULID;
   the `feature:*` middleware refuses a whole area when its flag is off.
4. **Controller** (`CauseController@show`): loads the cause with its
   relations, builds `PageMeta` (title, description, canonical, OG, JSON-LD
   via `StructuredData`), returns a view.
5. **View**: `components/layouts/app.blade.php` — theme class from the
   cookie, the inlined theme tokens (`ThemeTokens`, cached), the blocking
   theme script, the preloaded font, `@vite`, `@stack('head')`; then
   `components/site/page-shell.blade.php` with header (menus from
   `Menu::renderable()`, cached under the site generation), announcement,
   breadcrumbs, `<main>`, footer, cookie consent, newsletter popup,
   analytics (`text/plain` until consent). Rich text from the CMS passes
   through `@clean` (`App\Support\Html`).
6. **Response** goes back through the middleware: `CachePublicPage` stores
   it if it qualified, `CountVisit::terminate()` writes the four
   statistics upserts after the bytes have left.

For the admin: Filament's own middleware stack plus `SecurityHeaders`
(report-only CSP there — Filament needs inline scripts), `RestrictAdminByIp`,
`RecordAdminActivity`, the absolute-timeout and single-session rules
(`config/admin.php`), mandatory TOTP.

For a webhook: `PaystackWebhookController` → `PaymentManager::recordWebhook()`
→ 200 → queued job. `PAYMENTS.md` §3.

## 4. The services that matter

| Service | Why it exists |
|---|---|
| `Settings` / `setting()` | every piece of configuration the foundation can change lives in the `settings` table, typed, cached as strings and cast per process. `Settings::flush()` on save empties everything |
| `ThemeTokens` | the light and dark palettes as CSS custom properties, inlined in `<head>`, with the WCAG contrast checker in front of the editor |
| `BlockRegistry` | the closed vocabulary of page-builder blocks: each has a definition (fields, presentation options), a Blade view in `resources/views/blocks/`, and a resolver for the data it needs |
| `SiteCache` + `CachePublicPage` | the caching strategy without Redis: one generation number, fragments as arrays, whole pages for anonymous visitors (`PHASE-15-PERFORMANCE.md`) |
| `PaymentManager` and friends | `PAYMENTS.md` |
| `MessageDispatcher` | one door for every email and SMS: templates from the CMS, idempotency keys, the outbox (`scheduled_messages`), suppression checks, the send throttle (`PHASE-10-*.md`) |
| `MediaLibrary` / `UploadPolicy` / `ImageToolchain` | uploads with EXIF/GPS stripped synchronously, conversions sized for a 3G screen, the inode budget, the consent gate on any image that shows a person |
| `Html::clean()` / `@clean` | the HTML sanitiser every CMS rich-text field goes through on the way out |
| `SiteHealth` | eighteen checks a shared host fails silently on (cron, queue, backups, restore test, storage, inodes, keys, mail, SMS, HTTPS, indexing, contrast…), and `scghf:preflight` for the deploy script |
| `RetentionRunner` | Act 843 retention on a schedule with legal holds and a log; never the money tables |
| `AuditLogger` / `audit_logs` | the hash-chained audit trail, archived by closed year |

## 5. Design decisions, and the reason for each

| Decision | Because |
|---|---|
| Money is integer pesewas everywhere; `Money` value object; `currency` on every gateway call | a float on a donation is a rounding error somebody has to explain to an auditor. `CLAUDE.md` |
| Truth comes from the signed webhook; the redirect only *reads* what the database already says; `settle()` refuses a mismatch and never reopens a final state | the browser can be closed, replayed, or forged. The webhook can only be replayed, and replay is a database unique constraint |
| No Livewire on the public site; one small module | LCP under 2.5 s on a low-end Android on 3G was the budget; Livewire's runtime alone is more than the whole public bundle |
| Filament for the admin, at a non-default path, with mandatory TOTP | a non-technical team needed a complete CMS on day one; a panel at `/admin` is a scanner's first guess |
| Content in the CMS, never in Blade; seeded defaults; placeholders like `{{PHONE_PRIMARY}}` treated as absent | the foundation must change its own phone number. `scghf:preflight` lists what is still a placeholder |
| Permissions, never roles, checked in code; `BasePolicy` maps abilities to `prefix.ability`; deny by default | the role matrix changes without a deploy; a renamed permission fails closed, not open |
| Cron-driven queue with `flock`, `--stop-when-empty`, `--max-time=55` | shared hosting has no Supervisor and kills daemons; a worker a minute is what it allows |
| File caches and a `cache` table; `serializable_classes` false; nothing cached is an object | no Redis; and a cache that unserializes objects is a gadget chain when `APP_KEY` leaks |
| One generation number invalidates every fragment and page | "forget the right key on save" fails the first time somebody adds a fragment; over-invalidation costs one render |
| Strict Eloquent outside production (`Model::shouldBeStrict`) | an N+1 or a missing attribute throws in development and tests instead of costing PHP seconds on the host |
| Photographs of people cannot be published without a consent record; EXIF stripped before the file is reachable | children's home coordinates in a JPEG header is the safeguarding failure nobody sees |
| Append-only donations, hash-chained audit log, two-person refunds | the controls a registered NGO is asked about |
| Sanitised rich text on output, nonce CSP on the public site | an editor's paste is untrusted input, and a CSP that allows inline scripts is not a CSP |
| Larastan L5 with a baseline; query budgets; browser tests | `PHASE-14-QA.md` |

## 6. Where to start reading

Public request: `routes/web.php` → a controller → `resources/views/components/site/page-shell.blade.php`.
A donation: `DonateController` → `DonationService` → `PaymentManager` → `PaystackWebhookController`.
The CMS: `app/Blocks/BlockRegistry.php` → `resources/views/blocks/` → `app/Filament/Resources/Pages`.
An email: `MessageDispatcher` → `TemplateRenderer` → `email_templates` (seeded by `MessageTemplateSeeder`).
The schedule: `routes/console.php`.
