# Troubleshooting — the twenty most likely failures

Start with **Site Health** (admin → System → Site Health): eighteen rows,
and the red one is usually the answer. Then `storage/logs/laravel-<date>.log`
on the server, then Sentry. Each entry below: what you see → what it is →
what to do.

| # | You see | It is | Do |
|---|---|---|---|
| 1 | Everything works except nothing scheduled happens: no receipts, no reminders, recurring gifts not charged. *Site Health → Scheduled jobs* says "Never run" or hours ago | The cron lines are missing or point at a path that no longer exists | cPanel → Cron Jobs; the two lines in `deploy/cpanel/cron.txt`; full PHP path; `current/artisan`. `DEPLOYMENT.md` §6 |
| 2 | Webhooks arrive (*Finance → Webhook events* fills up) but stay "not processed"; *Background queue* stale | The worker cron line is missing, or `flock` is holding a stale lock | Same as 1. If the line is there: `rm shared/queue.lock` then wait a minute. `php artisan queue:work --once` processes one now |
| 3 | A donor paid, Paystack shows it, the site shows nothing | Walk `PAYMENTS.md` §6, top to bottom | usually 2, 4 or 5 |
| 4 | Every webhook is "signature invalid" | `PAYSTACK_WEBHOOK_SECRET` is not the secret key of the account that took the payment (test vs live, or rotated) | fix `shared/.env`, `php artisan config:cache`, **Reprocess** the events |
| 5 | You changed `.env` on the server and nothing changed | the config is cached | `php artisan config:cache` in `current/`. Always |
| 6 | A blank white page, or "500" with nothing in the log | `storage/` or `bootstrap/cache` not writable (a deploy reset permissions), **or** the inode quota is full | *Site Health → File storage* and *File count*. `chmod -R 755 storage bootstrap/cache`; delete unused media; `PHASE-15-PERFORMANCE.md` §3.6 |
| 7 | 419 "Page expired" on every form | session cookies cannot be set: `SESSION_DOMAIN` wrong, `SESSION_SECURE_COOKIE=true` on plain HTTP, or the `sessions` table is unwritable | check those three keys; on staging over HTTPS `SESSION_SECURE_COOKIE=true` is right |
| 8 | Staff cannot sign in: "these credentials do not match" for a known-good password | the account is suspended or deactivated (*System → Staff accounts*), or the IP allowlist (`ADMIN_IP_ALLOWLIST`) excludes them | reactivate; or add the address; or clear the allowlist in `.env` and re-cache |
| 9 | Staff locked out of 2FA (lost phone) | recovery codes were issued at enrolment | a Super Admin resets their 2FA from *Staff accounts*; the next sign-in enrols again |
| 10 | Receipts land in spam, or never arrive | SPF/DKIM/DMARC not set on the sending subdomain, or the mail driver is `log` | *Site Health → Email sending*; `PHASE-10-EMAIL-DELIVERABILITY.md`; check `MAIL_MAILER` |
| 11 | SMS accepted by the gateway but never delivered | the sender ID is not registered on that network, or credit is out | *Site Health → SMS credits*; `PHASE-10-SMS-SENDER-ID.md`; the delivery reports on *Communications → SMS log* |
| 12 | An upload fails with "too large" although it is small | `MEDIA_MAX_IMAGE_MB` / `MEDIA_MAX_DOCUMENT_MB`, or the host's `upload_max_filesize`/`post_max_size` in the PHP selector are lower | raise the host's limits in cPanel → MultiPHP INI Editor to match |
| 13 | A photograph cannot be published: "needs consent" | it is marked as showing a person and has no consent record | add the consent on the image (*Media → Consents*) or mark it as not showing people; it is a safeguarding rule, not a bug |
| 14 | A page or menu edit does not show on the site | the page cache served a stored copy: the change came by a route the observers do not see (a raw update, a restore), or the TTL has not passed | `php artisan scghf:cache-clear`; if it recurs, the model is missing from `SiteCacheObserver`'s list (`AppServiceProvider`) |
| 15 | `X-Page-Cache: skip` on every public page for everyone | `PAGE_CACHE_ENABLED=false`, or the `pages` cache directory is unwritable | *Site Health → Page cache* |
| 16 | The site is slow only in the admin | Filament's assets missing after a deploy (`filament:assets` not run), or `APP_DEBUG=true` with the query log on | `php artisan filament:assets`; `APP_DEBUG=false` |
| 17 | "Payment amount or currency mismatch — held for review" in the log; a donation stuck in *needs review* | Paystack reported a different amount from the one we initialised. Deliberate: it is never auto-completed | read the event's payload; refund and ask the donor to try again, or file S1 if we are wrong. `PAYMENTS.md` §4 |
| 18 | The nightly backup fails, or the restore test row is red | the backup disk credentials, the archive password, or disk space at the destination | `PHASE-12-INFRASTRUCTURE.md`; `php artisan backup:run` by hand and read the output; `scghf:restore-test` |
| 19 | The deploy fails at "Preflight" or "Migration failed" | preflight: a placeholder still in settings or a missing key — fix in the admin/`.env` and redeploy. Migration: the live site is untouched; read the error, fix the migration, redeploy | `DEPLOYMENT.md` §2; never edit the schema by hand |
| 20 | After a rollback the site works but recent gifts are missing | the pre-deploy dump was restored over newer rows | `scghf:reconcile-payments --execute` re-records settled payments from Paystack; `DEPLOYMENT.md` §4 |

## When it is none of these

1. `php artisan scghf:preflight` on the server — it names what is wrong in plain words.
2. `tail -200 storage/logs/laravel-$(date +%F).log`.
3. Sentry, if `SENTRY_LARAVEL_DSN` is set: the stack trace with the release.
4. `php artisan tinker` and the model in question.
5. `SECURITY.md` if it looks like an attack; `OPERATIONS.md` for who to call.
