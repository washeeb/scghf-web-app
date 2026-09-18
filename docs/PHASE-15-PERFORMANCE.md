# Performance on shared hosting — what is built, what it measures, and when to leave

Companions: `PHASE-12-INFRASTRUCTURE.md` (Cloudflare, backups),
`PHASE-13-SEO-AND-CONTENT.md` §4 (the front-end budget and the image
pipeline), `PHASE-14-QA.md` §5 (load sanity).

## 1. The constraint

InMotion shared cPanel hosting meters three things a Laravel application
spends freely: **PHP processes** (entry processes, ~20–80 concurrent),
**CPU seconds**, and **files** (inodes, typically 250,000–300,000 for the
whole account). There is no Redis, no object cache, no persistent worker,
and the database is on the same box under the same quota. MySQL
connections are cheap; PHP booting the framework twenty times a second is
not. So the whole of Phase 15 is about doing less per request, and doing
the unavoidable off the request.

## 2. What was measured first

`tests/Feature/QueryBudgetTest.php` renders every kind of public page
against the demo data and counts statements. Before Phase 15 the **layout
alone cost 20 queries a page** — four menus at three queries each, the
announcement, three separate lookups of the cookie-policy page, and three
`COUNT(*)` for the visitor statistics — before any content was read.
Home: 24 queries. A blog post: 34, with the author and category loaded
three times.

## 3. What is built

### 3.1 Caching without Redis

| Layer | What | Where it lives | Emptied by |
|---|---|---|---|
| Config, routes, views, events, Filament, icons | Laravel's own compiled caches | `bootstrap/cache`, `storage/framework` | every deploy (`activate.sh`) |
| Settings | the whole table, **as stored strings with their type**, cast per process | default store (`cache` table) | `Settings::flush()` on any save |
| Theme tokens | the CSS custom properties | default store | theme save |
| Fragments — menus, announcement, policy links | plain arrays under one **generation number** | `FRAGMENT_CACHE_STORE` (file) | any content save bumps the generation |
| Donation totals | `causes.raised_minor`, incremented in SQL; the pages that show it | the page cache | `Cause::recordDonation()` bumps the generation |
| **Full pages** for anonymous visitors | the HTML, the nonce and CSRF token it was rendered with | `PAGE_CACHE_STORE` (file, `storage/framework/cache/pages`) | generation bump; 10-minute TTL |
| Sitemaps, impact figures | per type / one hour | default store | sitemap observer / TTL |

**The generation number** (`App\Support\SiteCache`) is the whole
invalidation strategy. Every key carries it; any save on any of 36 content
models, a settings save, or a donation completing starts a new one. Nothing
has to know which key to forget, and over-invalidation costs one extra
render. Inside an HTTP request a second bump is skipped when nothing has
read the generation since the first (an import is one change, not five
hundred); a console process bumps every time.

**The full-page cache** (`App\Http\Middleware\CachePublicPage`) is the
biggest single win and the one with rules:

- GET/HEAD only; 200 HTML only; no query string except `page`
- nobody signed in; no flash data in the session (an error, a status, a
  conversion event)
- never on the paths in `config/performance.php` (basket, checkout,
  account, admin, search, anything personal or signed) and never a
  response that sets its own cookie
- varies on every `scghf_*` cookie (theme, consent, dismissed
  announcements), so each variant is its own copy
- the **CSP nonce and CSRF token are swapped** into the stored body on
  every hit — both are random strings appearing nowhere else, so the
  replace is exact; forms and the nonce policy keep working
- `X-Page-Cache: hit | miss | skip` on every response

What it costs: a stored page is stale for at most `PAGE_CACHE_TTL` (600 s)
for a change the observers cannot see — a scheduled `published_at` passing,
an announcement's time window opening. The honeypot's encrypted timestamp
on a cached form is old, so the "filled too fast" check is weaker on a
cached page; the field-name check and the throttles are not.

**Why nothing in the cache is an object.** `cache.serializable_classes` is
`false` (Laravel 13's default, and the right one: a cache that
unserializes objects is a gadget chain the day `APP_KEY` leaks). Phase 15
found that the settings cache had been storing `Money` objects since Phase
2 — which came back as `__PHP_Incomplete_Class` from the **database**
store and had been invisible to the suite on the **array** store. Settings
now cache the stored string and its type and cast on the way out; menu
trees are cached as raw attribute arrays and rebuilt with
`newFromBuilder()`; encrypted settings stay encrypted in the cache table.

### 3.2 Queries

- Every public page has a query budget (§2) and the test fails on any
  statement repeated with the same bindings. Budgets after Phase 15,
  fragment cache warm, full-page cache off (the four visitor-statistics
  upserts included): home 7, donate 5, an appeal 11, a project 14, a post
  12, a product 12, the 404 page 4.
- Related posts: one ordered query instead of two, relations loaded once
  for the post and its related together.
- Indexes added for the maintenance jobs: `activity_log.created_at`,
  `email_logs.created_at`, `sms_logs.created_at`, and
  `(payload_archived_at, received_at)` on both webhook tables. The hot
  public queries had theirs since Phase 3 (`PHASE-3-DATA-ARCHITECTURE.md`).
- Everything that lists paginates (Phase 5–9); every export streams in
  chunks of 500 (`ExportAction`); campaigns send in `chunkById(500)`; the
  audit archive now streams a year through a gzip handle 500 rows at a
  time instead of loading it whole; the retention runner walks candidates
  with `lazyById(200)` and stops one past its ceiling.

### 3.3 The cron-driven queue

```
* * * * * flock -n …/shared/queue.lock php artisan queue:work --stop-when-empty --max-time=55 --timeout=50 --tries=3 --memory=128 --sleep=1 --max-jobs=250
```

| Flag | Why |
|---|---|
| `flock -n` | one worker at a time; a minute that is still running is not joined by the next |
| `--stop-when-empty --max-time=55` | never overlap the next cron tick |
| `--timeout=50` | a job cannot outlive the worker; `QUEUE_RETRY_AFTER=90` stays above it, so a killed job is retried by the next worker, never duplicated |
| `--memory=128` | a receipt PDF or a campaign batch needs more than the old 96 |
| `--sleep=1` | look again after a second when the queue empties mid-run (a donor is waiting on the receipt) |
| `--max-jobs=250` | a leak-proof ceiling on a host that meters CPU seconds per process |

Every scheduled command carries `withoutOverlapping()`; the two webhook
jobs declare `$timeout = 45` and a backoff of 10/30/120/300 s; campaign
and broadcast batches are sized in `config/communications.php`.

### 3.4 Assets and images

Verified against the brief rather than rebuilt: hashed filenames and
`immutable` caching (`.htaccess`), one 6.7 KB module and a 59 KB
stylesheet (2.5 / 10.6 KB gzipped), Tailwind v4 emitting only the classes
in use, two self-hosted variable fonts subset to latin and latin-ext with
`unicode-range` and `font-display: swap`, the body face preloaded, the
theme tokens inlined in `<head>`, gzip and brotli, `srcset`/WebP/lazy
loading with the hero eager and `fetchpriority="high"`.

Added in Phase 15: a `<link rel="preload" as="image">` for the hero crop
this screen will use, pushed into `<head>` so the preload scanner finds the
LCP image before the parser reaches the `<picture>`.

Decided against: an SVG sprite (seven inline icons, under 2 KB, would
become an extra request or an inline `<symbol>` block on every page) and
inlining the full stylesheet (10 KB per page that is otherwise cached
forever — the render-blocking sheet was measured in Phase 13 and kept).

Cloudflare in front is the single largest TTFB improvement available on
this host and needs a DNS change: `PHASE-12-INFRASTRUCTURE.md` §Cloudflare.
With it, every static file and (with a page rule) the home page are served
from the edge and never reach PHP.

### 3.5 Database maintenance

`scghf:db-maintain --execute`, monthly at 04:00 on the 1st: expired
sessions the lottery missed, visitor rows over 26 months, stale reset
tokens, failed jobs over 30 days, the activity log past its window;
`OPTIMIZE TABLE` on the churning tables (InnoDB never gives deleted space
back on its own); the ten largest tables reported.

`scghf:archive-webhook-payloads --execute`, monthly on the 2nd: the raw
bodies of processed webhook events older than a year move to monthly
gzipped JSON-lines files (`webhook-archives/`), each body with its SHA-256;
the rows stay — they are the audit trail — with the hash and the archive's
name; replay is hidden for them. `scghf:archive-audit-log` (Phase 12) does
the same for whole closed years of the audit trail.

Personal data is never pruned here. That is the retention runner's job,
with its holds and its log (`PHASE-12-DATA-PROTECTION.md`).

### 3.6 Inodes and disk

The media library writes an original and up to four conversions per
upload: **five files per photograph**. At 300,000 inodes for the account
and the application, vendor and framework caches using ~40,000, the
library has room for roughly 50,000 photographs — but a busy year of
uploads plus the page cache, sessions and logs is a number worth watching
rather than assuming.

- *Site Health → File count (inodes)*: the library's file count summed
  from what the database asked for, against `MEDIA_INODE_BUDGET`; warns
  at 70 %, critical at 90 %. Set the budget to the real quota from cPanel
  → Statistics.
- `php artisan scghf:media-doctor`: the breakdown, and orphaned files.
- The page cache is one file per page variant; a few hundred at most, and
  `scghf:cache-clear` empties it.
- Cleanup, in order of effect: delete unused uploads from the media
  library (the usage panel says where each is used); lower `MEDIA_AVIF`
  off if it is on (one file fewer per image); archive the audit log and
  the webhook bodies (§3.5); keep `backup:clean` running (each backup is
  one file, but the temp directory it builds in is thousands until it is
  zipped).
- Past the quota nothing can be written — a session, a cache file, an
  upload — and the site is a blank page. That is the symptom to know.

## 4. The budget and the numbers

### 4.1 Budget (mobile, CLAUDE.md and Phase 1)

LCP < 2.5 s on simulated slow 4G · CLS < 0.1 · INP/TBT < 200 ms · JS ≤ 100 KB
gzipped (CI enforces; actual 2.5 KB) · CSS ≤ 50 KB gzipped (actual 10.6 KB)
· Lighthouse Performance ≥ 90 mobile.

### 4.2 Lighthouse 12, mobile, simulated throttling, local `artisan serve`

Same five pages, before and after the full-page cache, demo data, both
runs on the developer's machine. **This server does not compress**, so
every transfer is the raw size (the audit reports "100 KiB of savings from
text compression" that the host's `.htaccess` already delivers), and its
TTFB floor is PHP booting once per request with no OPcache tuning.

| Page | Before: Perf / FCP / LCP / TTFB | After: Perf / FCP / LCP / TTFB |
|---|---|---|
| Home | 98 / 1.7 s / 2.1 s / 191 ms | 98 / 1.7 s / 2.1 s / 157 ms |
| Donate | 94 / 2.3 s / 2.7 s / 191 ms | 93 / 2.3 s / 2.8 s / 143 ms |
| Appeal | 95 / 2.1 s / 2.6 s / 332 ms | 94 / 2.2 s / 2.6 s / 152 ms |
| Shop | 96 / 2.0 s / 2.4 s / 199 ms | 95 / 2.1 s / 2.6 s / 153 ms |
| Post | 93 / 2.4 s / 2.7 s / 192 ms | 92 / 2.4 s / 2.9 s / 140 ms |

TBT 0 ms and CLS 0 on every page, both runs. Accessibility 100, Best
practices 100; SEO 66 locally only because the local site is `noindex`.

What the table says, honestly: on a developer machine the render was never
the bottleneck — 20 queries cost a few milliseconds here — and Lighthouse's
simulated numbers are the front end, which Phase 13 had already put under
budget. The ±0.1 s between columns is run-to-run noise. The change that
matters is the one the table shows least: TTFB from 190–330 ms to a flat
140–157 ms, which is the floor of this server. On the host, where a render
was 20 queries over a shared MySQL and PHP-FPM under a CPU quota, that
difference is the one a donor on a phone feels.

### 4.3 What to measure on staging

Lighthouse mobile on the same five pages of staging (with compression, with
OPcache, without Cloudflare) and again with Cloudflare in front. Record
them below. Expect Performance 90–96 without Cloudflare and TTFB of
300–600 ms for a miss, under 200 ms for a hit; with Cloudflare, static
files at ~30 ms.

| Date | Environment | Home | Donate | Appeal | Shop | Post | Notes |
|---|---|---|---|---|---|---|---|
| 2026-09-18 | local, no compression | 98 | 93 | 94 | 95 | 92 | page cache on |
| | staging | | | | | | |
| | staging + Cloudflare | | | | | | |
| | production | | | | | | |

## 5. When shared hosting stops being enough, and what to do about it

### 5.1 The signs

Any two of these for a fortnight, and it is time:

- cPanel → *Resource Usage* shows entry-process or CPU faults during
  normal hours, not only during a campaign
- `X-Page-Cache: miss` responses over 800 ms at p95 on staging with
  nothing else running (the `scripts/load/browse.js` numbers)
- the queue heartbeat on Site Health goes stale more than once a week —
  the minute-cron worker is not keeping up with receipts and reminders
- the inode row on Site Health is past 70 % with the cleanup in §3.6 done
- the nightly backup takes over an hour or fails on size
- more than ~5,000 gifts a month, or a campaign that sends to more than
  ~20,000 subscribers (the throttled sender is sized for shared hosting)

### 5.2 The path: a small VPS, the same application

Nothing in the application assumes shared hosting; it only tolerates it.
The move is values and services, not code:

1. **A 2 vCPU / 4 GB VPS** (a local provider or a Frankfurt/London droplet;
   ~US$20–25 a month), Ubuntu LTS, PHP 8.4-FPM, MySQL 8 or MariaDB,
   Nginx, Redis. Laravel Forge or Ploi can build it in an afternoon; the
   deploy workflow already targets SSH + rsync and needs only the secrets
   changed.
2. **Redis** for `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION`.
   The application already reads all three from `.env`; the page and
   fragment caches keep their file stores or move to Redis by one variable
   each. `serializable_classes` stays `false`.
3. **A real worker**: Supervisor running `queue:work` as a daemon replaces
   the minute cron; the scheduler cron line stays. Delete the `flock` line.
4. **Nginx + OPcache tuned** (`opcache.validate_timestamps=0`,
   `opcache.memory_consumption=256`), HTTP/2, brotli. Expect TTFB under
   100 ms for a miss.
5. **Media to object storage** (S3-compatible: DigitalOcean Spaces or
   Backblaze B2) with Cloudflare in front — the inode question goes away,
   and backups become a bucket versioning policy. `FILESYSTEM_DISK` and
   the media library's disk are config; the `MEDIA_*` conversions are
   unchanged.
6. **Backups off-box** as today (`spatie/laravel-backup` to the same
   bucket), plus the provider's snapshots.
7. Keep the shared account alive for a month as the fallback, then close
   it.

What does not change: the code, the tests, the CMS, the money rules, the
security posture (the `SecurityHeaders` middleware and the `.htaccess`
rules become Nginx directives — the doc for that is a page, not a phase).

### 5.3 What not to do

Do not move to a "managed WordPress-style" PHP host to buy time; the
application needs cron, Composer and a writable `storage/`, and the
migration cost is the same as a VPS with none of the benefit. Do not add
Redis on the shared account through a third-party service across the
internet; the round trip is slower than the `cache` table.

## 6. Open items

| Item | Who | Why |
|---|---|---|
| Lighthouse on staging, with and without Cloudflare | developer, once staging exists | §4.3; local numbers cannot show the host |
| `MEDIA_INODE_BUDGET` set from cPanel → Statistics | developer | the health row is only as honest as the number |
| Cloudflare DNS change | domain owner | `PHASE-12-INFRASTRUCTURE.md`; the biggest TTFB win available |
| First monthly maintenance run watched | developer | `scghf:db-maintain` and `scghf:archive-webhook-payloads` run on the 1st and 2nd; read the output once |
