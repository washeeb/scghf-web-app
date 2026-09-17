# Infrastructure: backups and the restore test, Cloudflare, monitoring, incidents, patching

## 1. Backups — and the restore that proves them

### What runs

| When | What | Where it goes |
|---|---|---|
| 02:00 daily | `backup:run` — database dump + `storage/app` files, zipped, **password-protected** (`BACKUP_ARCHIVE_PASSWORD`) | the `BACKUP_DISK` |
| 03:00 daily | `backup:clean` — keeps 14 daily, 8 weekly, 6 monthly, under `BACKUP_MAX_MEGABYTES` | |
| every run | `RecordBackupOutcome` writes `backups_log`; a failure emails `BACKUP_NOTIFICATION_EMAIL` (failures only, never successes) | |
| Site Health | *Backups* row goes warning after 2 days without one; *Restore test* row goes warning after 90 days without one | |

### Off-server storage — a decision the trustees must take before launch

A backup on the same cPanel account is not a backup: it dies with the
account. `BACKUP_DISK` should point at an S3-compatible bucket:

| Option | Cost for ~5 GB | Setup |
|---|---|---|
| **Backblaze B2** (recommended) | ~$0.03/month; first 10 GB free | Create a bucket (private), an application key limited to it; `FILESYSTEM`: `AWS_ENDPOINT=https://s3.<region>.backblazeb2.com`, `AWS_BUCKET`, key/secret, `AWS_DEFAULT_REGION=<region>`, `BACKUP_DISK=s3` |
| Cloudflare R2 | free to 10 GB, no egress fees | Same shape: `AWS_ENDPOINT=https://<account>.r2.cloudflarestorage.com` |
| Google Drive / Dropbox | free | Needs an extra Flysystem adapter package and an OAuth dance; not recommended for something unattended |

Then `php artisan backup:run` once by hand and confirm the object appears
in the bucket. **Set `BACKUP_ARCHIVE_PASSWORD` first** and store it in
the password manager: the archives hold donor data.

### The restore test — quarterly, and it is a command

```bash
php artisan scghf:restore-test --verified-by=you@greaterhopefoundations.com
```

It takes the newest completed backup, opens it with the archive
password, streams the database dump into `RESTORE_TEST_DATABASE` (an
empty database created once in cPanel → MySQL Databases and granted to
the same user — **never the live one**; the command refuses if the names
match), counts the rows in `users`, `donors`, `donations`,
`payment_transactions`, `orders`, `pages`, `media`, `audit_logs` against
the live counts, prints the table, records the test in `backups_log`
with your name, audits it, and wipes the scratch database. Site Health
stops asking for 90 days.

If it fails: the error is the first thing to fix — a wrong archive
password, a dump the archive does not contain, a scratch database the
user cannot write. A backup you cannot restore is not a backup.

**Full restore (files as well), when the day comes:** download the
archive, `unzip -P <password>`, `db-dumps/mysql-*.sql` into the live
database via cPanel → phpMyAdmin → Import (or the command above's
approach), `storage/app` back into `shared/storage/app`, then
`php artisan storage:link`, `config:cache`, `media:regenerate` if
conversions are missing. Rehearse it on staging once a year.

## 2. Cloudflare in front of the site

Why: a WAF, rate limiting and DDoS absorption the application cannot
provide, TLS at the edge, and caching of the static assets. Do this at
launch, not after the first incident.

1. **DNS.** Add the domain to Cloudflare (free plan), change the
   nameservers at the registrar, proxy (orange cloud) `@` and `www`.
   Leave `mail.` and `cpanel.` **grey** (DNS-only) — Cloudflare proxies
   HTTP only, and the sending subdomain's SPF/DKIM records are plain DNS.
2. **SSL/TLS → Full (strict).** cPanel already has AutoSSL; "Full
   (strict)" makes Cloudflare verify it. Never "Flexible": that is
   plaintext between Cloudflare and InMotion, with the browser shown a
   padlock.
3. **Edge certificates:** Always Use HTTPS on; Automatic HTTPS Rewrites
   on; minimum TLS 1.2; HSTS **off at Cloudflare** — the application
   sends it from `HSTS_MAX_AGE`, one source.
4. **Caching → Cache Rules.** Cloudflare caches static files by extension
   already. Add one rule, **Bypass cache**, for anything that must never
   be cached — expression:
   `(http.request.uri.path contains "/scghf-office") or (http.request.uri.path contains "/account") or (http.request.uri.path contains "/donate") or (http.request.uri.path contains "/shop/checkout") or (http.request.uri.path contains "/shop/cart") or (http.request.uri.path contains "/webhooks") or (http.request.uri.path contains "/livewire") or (http.cookie contains "scghf_session")`
   The last clause is the one that matters: any request carrying the
   session cookie is a person, and a person's page is never shared.
5. **Security → WAF.** Managed ruleset on (free tier includes the
   Cloudflare Managed Ruleset core). Add custom rules:
   - Block: `(http.request.uri.path contains "/.env") or (http.request.uri.path contains "/.git") or (http.request.uri.path contains "wp-login") or (http.request.uri.path contains "xmlrpc")`
   - Rate limit: `/login`, `/scghf-office/login`, `/password/*`: 10 requests / minute / IP → block 10 minutes.
   - Rate limit: `/donate/*` POST: 30 / minute / IP (a legitimate donor never needs more).
   - Rate limit: `/csp-report`: 60 / minute / IP.
   - **Never** rate-limit `/webhooks/*` — Paystack's retries must land.
6. **Bots:** Bot Fight Mode on. Turnstile is already on the donation form.
7. **Restrict origin to Cloudflare** so nobody bypasses the WAF by hitting
   InMotion's IP. In `public/.htaccess`, after the DNS has moved:
   ```apache
   # Only Cloudflare may talk to the origin. Ranges: cloudflare.com/ips
   <RequireAny>
       Require ip 173.245.48.0/20 103.21.244.0/22 103.22.200.0/22 103.31.4.0/22 141.101.64.0/18 108.162.192.0/18 190.93.240.0/20 188.114.96.0/20 197.234.240.0/22 198.41.128.0/17 162.158.0.0/15 104.16.0.0/13 104.24.0.0/14 172.64.0.0/13 131.0.72.0/22
   </RequireAny>
   ```
   and set `TRUSTED_PROXIES=*` in `.env` so `X-Forwarded-For` is
   believed — which is what makes the rate limits and the IP allowlist see
   the visitor's address rather than Cloudflare's. **The cron jobs still
   work** (they do not go through HTTP). Keep the range list current
   (Cloudflare changes it rarely; check yearly).
8. **Page Rules / redirects:** `www` → apex (or the reverse; pick one and
   match `APP_URL`).

## 3. Monitoring

| What | How | Cost |
|---|---|---|
| **Uptime** | UptimeRobot (free, 5-minute checks) on `https://<site>/up` — Laravel's health endpoint, which returns 200 only when the app boots and the database answers. Alert to the alerts address and a phone. Add a second monitor on `/donate` (keyword: "Donate") so a broken donation page is noticed, not just a dead server. | free |
| **Errors** | Sentry, free tier (5,000 events/month): `SENTRY_LARAVEL_DSN`. Errors only — traces sampled at 0, `send_default_pii=false`, so no donor data leaves. In-app *Error reports* remain for staff. Site Health says whether it is connected. | free |
| **Scheduler, queue, backups, restore test, mail, SMS credits, Paystack mode** | Site Health page and `scghf:preflight`; the weekly summary email (Phase 10). | — |
| **Money** | `scghf:payment-anomalies` hourly; mismatch alerts at once; `scghf:reconcile-payments` daily. | — |
| **Deliverability** | Resend dashboard bounce/complaint rates; DMARC reports (`PHASE-10-EMAIL-DELIVERABILITY.md`). | — |

## 4. Incident response runbook

Keep this page printed in the office. Every incident: **note the time**,
**one person leads**, **write down what you did** (a shared document is
fine), and afterwards fill in the incident log (below) and fix the cause.

### Site down

1. Is it the site or the network? `https://<site>/up` from a phone on
   mobile data. UptimeRobot's alert says what it saw.
2. cPanel → is the account suspended (billing) or over disk/inode quota
   (cPanel → Disk Usage)? Both produce a dead site with no application
   error.
3. cPanel → Errors, and `storage/logs/laravel.log` (File Manager). A
   `500` after a deploy: run the **rollback** — `deploy/scripts/rollback.sh`
   flips the symlink to the previous release (the deploy workflow does this
   itself if the smoke test failed).
4. Database down (`SQLSTATE[HY000] [2002]`): cPanel → MySQL Databases →
   Repair; then InMotion support.
5. Cloudflare status page if everything at InMotion looks fine.
6. Once up: check the queue ran (Site Health) — messages queued during
   the outage go out on their own.

### Payment gateway down

1. Paystack's status page. If Paystack is down, donors see a failed
   payment; nothing on our side is wrong.
2. Put the announcement bar up (*Content → Announcements*): "Card and
   mobile money payments are temporarily unavailable; try again in an
   hour, or give by Mobile Money to [number]." The offline giving details
   are on the donate page already.
3. Do **not** switch `PAYMENT_DRIVER` to `fake` on production, ever.
4. Afterwards: `scghf:reconcile-payments` compares our ledger with
   Paystack's; any payment that completed at Paystack during the outage
   but whose webhook never arrived shows up there. *Finance → Webhook
   events → Replay* for any that arrived unsigned.

### Data breach (suspected or confirmed)

1. **Contain first.** Suspend the account involved (*System → Staff
   accounts → Suspend*, which ends its sessions), rotate the secret that
   leaked (`PHASE-12-SECURITY.md` rotation table), take the site into
   maintenance mode if data is actively leaving (`php artisan down
   --secret=<token>`).
2. **Preserve evidence.** Do not delete logs. Export the audit trail for
   the period (*System → Audit log → Export*); `scghf:verify-audit-log`
   proves the chain is intact.
3. **Assess.** Which tables, whose data, how many people, was it
   encrypted (the sensitive columns are), for how long.
4. **Notify.** Act 843 s.31: the Data Protection Commission and the
   affected people "as soon as reasonably practicable". GDPR art.33 for
   donors abroad: the supervisory authority within **72 hours** of
   becoming aware; the people without undue delay when the risk is high.
   The DPC's breach form is on dataprotection.org.gh. Tell people plainly
   what happened, what data, what you did, what they should do.
5. **Paystack**: if payment references or the secret key were involved,
   tell them (SAQ-A obligation).
6. **Learn.** Incident log entry; the fix; a retest.

### Defacement

1. Rollback to the previous release (symlink flip) — a deface is a
   changed file, and the previous release still has the right ones.
2. Change every password and key (rotation table); the attacker got in
   somehow. Check cPanel → SSH Access for keys you do not recognise,
   cPanel → Cron Jobs for lines you did not write, `.htaccess` for
   redirects.
3. `git status` on the server shows changed files if the release was
   edited in place; `git diff` shows what.
4. Check `public/` for files that are not in the repository (a webshell
   is usually a `.php` file with an innocent name).
5. Then the data-breach steps — a defacer had write access, so assume
   read access.

### Mail blacklisting / receipts in spam

1. Check the sending domain at mxtoolbox.com/blacklists. A listing of
   the *shared InMotion IP* is not ours to fix — and is why mail leaves
   through Resend, not cPanel.
2. Resend dashboard → bounce and complaint rates. Above 4 % / 0.1 %:
   pause the newsletter (*Communications → Campaigns → Pause*).
3. DMARC reports: is somebody else sending as us? Move the policy to
   `p=reject`.
4. Check the suppression list is being fed (Site Health → mail, the
   webhook events) — a dead webhook secret means bounces are not
   suppressing.

### Incident log

Keep it in the admin (*System → Documents*, private) or the shared
drive:

| Date/time | What happened | Detected by | Lead | Actions | Root cause | Follow-up |
|---|---|---|---|---|---|---|

## 5. Dependencies and the monthly patch routine

- **Automated:** Dependabot opens PRs weekly for Composer and npm
  (`.github/dependabot.yml`); CI runs `composer audit` and `npm audit
  --audit-level=high` on every push and **fails the build** on a known
  vulnerability in a shipped dependency.
- **Monthly, first Monday, 30 minutes:**
  1. Merge the Dependabot PRs that pass CI (patch and minor). Majors get
     a branch and a look at the changelog.
  2. `composer outdated --direct` locally; anything a major behind gets a
     ticket.
  3. cPanel → MultiPHP Manager: is a newer PHP 8.4.x available? Select it
     (staging first).
  4. Check Laravel's and Filament's security advisories (GitHub → Security
     → Advisories on each repo).
  5. Deploy to staging, run the smoke checks, deploy to production.
  6. Note the date in the incident log's "patching" row.
- **Immediately** for anything Dependabot marks as a security update to
  `laravel/framework`, `livewire/livewire`, `filament/*`,
  `spatie/laravel-medialibrary` or `symfony/*`.
