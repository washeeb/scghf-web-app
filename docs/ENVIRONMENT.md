# Environment reference — every `.env` key

Generated from `.env.example` by `docs/tools/env_reference.py`; regenerate after adding a key. The rule (`CLAUDE.md`): every key in `.env.example` is read by something, and every key the code reads is in `.env.example`. §2 is the check. Real values live only in `shared/.env` on the server; after changing one there, `php artisan config:cache`.

189 keys in 17 sections.

## 1. Keys by section

### Application

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `APP_NAME` | `"St. Cecilia's Greater Hope Foundations"     # [Decide] {{LEGAL_NAME}}` |  |
| `APP_ENV` | `local` | local \| staging \| production |
| `APP_KEY` | *(empty)* | [Generated] php artisan key:generate |
| `APP_PREVIOUS_KEYS` | *(empty)* | When rotating APP_KEY (a leak), put the OLD key here, comma-separated, so encrypted settings, cookies and signed URLs made under it still decrypt while the new key takes over. Empty otherwise. Read by config/app.php. |
| `APP_DEBUG` | `true` | MUST be false in staging + production |
| `APP_URL` | `http://localhost` | prod: https://greaterhopefoundations.com |
| `APP_TIMEZONE` | `Africa/Accra` | Ghana — GMT, no DST |
| `APP_LOCALE` | `en` |  |
| `APP_FALLBACK_LOCALE` | `en` |  |
| `APP_FAKER_LOCALE` | `en_GB` |  |
| `APP_MAINTENANCE_DRIVER` | `file` |  |
| `APP_RELEASE` | `local` | Human-readable build marker, set by CI at deploy time. Shown on Site Health → Environment. |

### Logging

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `LOG_CHANNEL` | `daily` | 'daily' not 'single': shared hosting has a disk quota and an inode budget. |
| `LOG_STACK` | `daily` |  |
| `LOG_LEVEL` | `debug` | production: warning |
| `LOG_DAILY_DAYS` | `14` |  |
| `LOG_DEPRECATIONS_CHANNEL` | `null` |  |

### Database

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `DB_CONNECTION` | `mysql` | [cPanel] Databases → Database Wizard. cPanel prefixes everything with the account username, so the names below are already correct for account presti98. |
| `DB_HOST` | `127.0.0.1` | server: localhost |
| `DB_PORT` | `3306` |  |
| `DB_DATABASE` | `presti98_scghf_prod` | staging: presti98_scghf_stage |
| `RESTORE_TEST_DATABASE` | `presti98_scghf_restore` | An empty scratch database for the quarterly restore test (scghf:restore-test), created in cPanel → MySQL Databases and granted to the same user. Never the live one. |
| `DB_USERNAME` | `presti98_scghf` | staging: presti98_scghfstg |
| `DB_PASSWORD` | *(empty)* | [cPanel] generated in Database Wizard |
| `DB_CHARSET` | `utf8mb4` |  |
| `DB_COLLATION` | `utf8mb4_unicode_ci` |  |

### Session / cache / queue

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `SESSION_DRIVER` | `database` | All 'database': InMotion shared hosting has no Redis and no Memcached. |
| `SESSION_LIFETIME` | `120` |  |
| `SESSION_ENCRYPT` | `false` |  |
| `SESSION_PATH` | `/` |  |
| `SESSION_DOMAIN` | `null` |  |
| `SESSION_SECURE_COOKIE` | `false` | true in staging + production |
| `SESSION_SAME_SITE` | `lax` |  |
| `SESSION_HTTP_ONLY` | `true` |  |
| `CACHE_STORE` | `database` |  |
| `CACHE_PREFIX` | `scghf` |  |
| `PAGE_CACHE_ENABLED` | `true` | Full-page cache for anonymous visitors (Phase 15, App\Http\Middleware\CachePublicPage). Public GET pages only; never the basket, checkout, account, admin, search or anything personal; the CSP nonce and CSRF token are swapped in per hit; any content save empties it. PAGE_CACHE_STORE is its own file store ('pages') so a hit never opens a database connection. TTL is the safety net for changes the observers cannot see (a scheduled publish date passing): ten minutes. |
| `PAGE_CACHE_STORE` | `pages` |  |
| `PAGE_CACHE_TTL` | `600` |  |
| `FRAGMENT_CACHE_STORE` | `file` | Menus, the announcement, policy links and other fragments: cached under one generation number that every content save bumps, in the file store so a hit is not a database round trip. The TTL is the backstop. |
| `FRAGMENT_CACHE_TTL` | `3600` |  |
| `QUEUE_CONNECTION` | `database` |  |
| `QUEUE_FAILED_DRIVER` | `database-uuids` |  |
| `QUEUE_RETRY_AFTER` | `90` | Seconds a job may run. Kept under the cron worker's --max-time so nothing is killed mid-flight by the 60-second cron boundary. |

### Filesystem & media

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `FILESYSTEM_DISK` | `public` | 'public' = storage/app/public, exposed via the storage:link symlink. Switching to object storage later (risk SH-6, inode exhaustion) is a change to this one value plus the disk credentials — no application code changes. |
| `MEDIA_DISK` | `public` |  |
| `MEDIA_MAX_IMAGE_MB` | `8` | ⚠ MEDIA_MAX_UPLOAD_MB used to be documented here and was read by nothing — the same class of gap the CLAUDE.md .env rule exists to catch. These two replace it, split because a photograph and a policy PDF are different sizes. Both are the OUTER limit. PHP's own upload_max_filesize and post_max_size are frequently smaller on shared hosting and are the real ceiling; run `php artisan scghf:media-doctor` ON THE SERVER to see what they actually are. |
| `MEDIA_MAX_DOCUMENT_MB` | `20` |  |
| `MEDIA_STRIP_EXIF` | `true` | GPS in beneficiary photos — never optional |
| `IMAGE_DRIVER` | `auto` | Which image driver to use. `auto` asks the server: Imagick where the account has it, GD otherwise. Imagick is preferred because it does not hold the decompressed bitmap inside PHP's memory_limit the way GD does, which on a 128MB shared account is the difference between a 6000px photograph converting and a white screen. Set gd or imagick only to reproduce a problem locally. |
| `MEDIA_WEBP` | `true` | WebP for every conversion — roughly a third fewer bytes than JPEG at the same perceived quality. Skipped automatically if the account cannot encode it. |
| `MEDIA_AVIF` | `false` | AVIF is smaller still and OFF on purpose: encoding costs seconds rather than milliseconds per image on a shared CPU, which on a bulk upload is a request that times out halfway through. Turn it on only after scghf:media-doctor says the account can encode it, and only with conversions queued. |
| `MEDIA_INODE_BUDGET` | `200000` | Shared hosting counts FILES, not bytes, and a media library is what exhausts that count — quietly, one upload at a time, until a deploy fails to write. Set this to the account's real inode quota; the default is a stand-in. |
| `AWS_ACCESS_KEY_ID` | *(empty)* | Optional object-storage escape hatch (Cloudflare R2 / Backblaze B2, S3-compatible). Switched on by pointing FILESYSTEM_DISK or MEDIA_DISK at s3; these are its credentials. |
| `AWS_SECRET_ACCESS_KEY` | *(empty)* |  |
| `AWS_DEFAULT_REGION` | `auto` |  |
| `AWS_BUCKET` | *(empty)* |  |
| `AWS_ENDPOINT` | *(empty)* |  |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` |  |

### Mail

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `MAIL_MAILER` | `log` | Receipts must NOT leave from the shared cPanel IP — its reputation belongs to every tenant on it (PHASE-1-BLUEPRINT.md risk DEL-3). Decision, Phase 10: Resend over its API, on a sending subdomain (mail.greaterhopefoundations.com) with SPF, DKIM and DMARC published. Rationale, alternatives and the DNS records: docs/PHASE-10-EMAIL-DELIVERABILITY.md. log    — local and tests. Every message is rendered and logged, none sent. resend — production. Needs RESEND_API_KEY; bounces come back through RESEND_WEBHOOK_SECRET below. smtp   — any provider's SMTP relay (Brevo, Mailgun, Postmark, …) with its credentials in MAIL_HOST/USERNAME/PASSWORD. Also cPanel's own mailbox, which the health page flags as the shared-IP risk. |
| `MAIL_HOST` | `smtp-relay.brevo.com` | only for MAIL_MAILER=smtp |
| `MAIL_PORT` | `587` |  |
| `MAIL_USERNAME` | *(empty)* | relay login (Brevo: the account email; Mailgun: postmaster@domain) |
| `MAIL_PASSWORD` | *(empty)* | relay key — never the cPanel mailbox password in a repo |
| `MAIL_SCHEME` | *(empty)* | blank = STARTTLS on 587; smtps for 465 |
| `MAIL_FROM_ADDRESS` | `"noreply@mail.greaterhopefoundations.com" # on the SENDING subdomain, see the deliverability doc` |  |
| `MAIL_FROM_NAME` | `"${APP_NAME}"` |  |
| `MAIL_REPLY_TO_ADDRESS` | `"info@greaterhopefoundations.com" # {{EMAIL_GENERAL}} — a mailbox a person reads` |  |
| `MAIL_BULK_PER_MINUTE` | `20` | Bulk/newsletter sending is throttled so no provider's hourly limit is breached in one burst (risk DEL-2). Resend's free tier is 100/day, 3,000/month; the paid tier has no hourly cap. Tune to the tier in use. |
| `MAIL_BULK_PER_HOUR` | `200` |  |
| `MAIL_ENABLED` | `true` | false still RENDERS and LOGS every message, sends none |
| `RESEND_API_KEY` | *(empty)* | [Resend] API Keys → "Sending access" only, scoped to the domain |

### SMS

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `SMS_DRIVER` | `log` | Provider: mNotify. Sender ID: GreaterHope (confirmed 2026-09-04). 'log' writes every message to sms_logs exactly as if sent, costed and segmented, but sends nothing — the right setting until MNOTIFY_API_KEY exists. Switching to the live provider is this one line. See PHASE-1-BLUEPRINT.md §11.6.3. log \| mnotify \| arkesel \| hubtel \| twilio — see docs/PHASE-10-SMS-SENDER-ID.md |
| `SMS_SENDER_ID` | `GreaterHope` | [SMS] exactly as registered — 11 chars is the GSM maximum |
| `SMS_ENABLED` | `true` |  |
| `SMS_MONTHLY_BUDGET_GHS` | `200` | alert threshold, not a hard stop |
| `SMS_LOW_BALANCE_THRESHOLD` | `50` |  |
| `SMS_PER_MINUTE` | `60` |  |
| `SMS_PER_HOUR` | `1000` |  |
| `SMS_COST_PER_SEGMENT_MINOR` | `4` | PESEWAS per segment; replace with the contracted rate |
| `SMS_MAX_SEGMENTS` | `2` | a longer template is REFUSED — 3 segments is 3x the cost |

### Communications

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `COMMS_BATCH_SIZE` | `25` | How many messages one cron invocation drains. The queue worker runs with --max-time=55, so this must finish comfortably inside a minute. |
| `COMMS_TRACK_OPENS` | `false` | Open and click tracking are OFF, on purpose. Recording that a named person read a message, when, is Act 843 processing that needs its own lawful basis and its own line in the privacy notice — a decision for the trustees, not a default somebody inherits. |
| `COMMS_TRACK_CLICKS` | `false` |  |
| `MNOTIFY_API_KEY` | *(empty)* | The chosen provider. Production refuses to boot with SMS_DRIVER=mnotify and no key — that configuration cannot send a single message, and discovering it one failed receipt at a time is the expensive way to find out. [SMS] mNotify dashboard → API key |
| `MNOTIFY_BASE_URL` | `https://api.mnotify.com/api` |  |
| `MNOTIFY_TIMEOUT` | `15` | seconds; short, so a hung request cannot take a whole batch down |
| `ARKESEL_API_KEY` | *(empty)* | The other three gateways, behind the same contract. Fill in the one you use and pick it in Settings → Communications (the .env SMS_DRIVER is the default). Arkesel and mNotify report a credit balance; Twilio reports money; Hubtel bills a merchant account and reports nothing on the SMS API. [SMS] Arkesel dashboard → API keys (v2) |
| `ARKESEL_BASE_URL` | `https://sms.arkesel.com/api/v2` |  |
| `ARKESEL_TIMEOUT` | `15` |  |
| `HUBTEL_CLIENT_ID` | *(empty)* | [SMS] Hubtel → API keys |
| `HUBTEL_CLIENT_SECRET` | *(empty)* |  |
| `HUBTEL_BASE_URL` | `https://smsc.hubtel.com/v1` |  |
| `HUBTEL_TIMEOUT` | `15` |  |
| `TWILIO_ACCOUNT_SID` | *(empty)* | Twilio is the fallback: international routes into Ghana cost several times a local aggregator's rate and an alphanumeric sender ID is not guaranteed on every network. Use a messaging service SID or a From number. |
| `TWILIO_AUTH_TOKEN` | *(empty)* |  |
| `TWILIO_FROM` | *(empty)* |  |
| `TWILIO_MESSAGING_SERVICE_SID` | *(empty)* |  |
| `TWILIO_BASE_URL` | `https://api.twilio.com/2010-04-01` |  |
| `TWILIO_TIMEOUT` | `15` |  |

### Payments — Paystack

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `PAYMENT_DRIVER` | `fake` | [Later] Merchant account not open yet. PAYMENT_DRIVER=fake returns deterministic fixtures so the whole payments module is built and tested without credentials. See PHASE-1-BLUEPRINT.md §11.6.2. Production refuses to boot with 'fake'. fake \| paystack |
| `PAYSTACK_PUBLIC_KEY` | `pk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx` | [Paystack] |
| `PAYSTACK_SECRET_KEY` | `sk_test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx` | [Paystack] NEVER commit a real one |
| `PAYSTACK_WEBHOOK_SECRET` | *(empty)* | [Paystack] blank = use the secret key |
| `PAYSTACK_BASE_URL` | `https://api.paystack.co` |  |
| `PAYSTACK_CURRENCY` | `GHS` | never change — every call sends this |
| `PAYSTACK_CALLBACK_URL` | `"${APP_URL}/donate/callback"` |  |
| `PAYSTACK_WEBHOOK_PATH` | `/webhooks/paystack` |  |
| `PAYSTACK_TIMEOUT` | `20` |  |
| `PAYSTACK_CHANNELS` | `"mobile_money,card"                  # MoMo first — it is how Ghana pays` |  |
| `PAYMENT_ALERT_FAILED_PER_HOUR` | `10` | Paystack's published webhook source IPs, comma-separated. When set, a delivery from any other address is stored as evidence but never processed. Leave blank to rely on the HMAC signature alone (which is already the real check). Anomaly alerts (scghf:payment-anomalies, hourly): more than this many failed payments in an hour, or refunds approved in a day, emails the alerts address. |
| `PAYMENT_ALERT_REFUNDS_PER_DAY` | `3` |  |
| `PAYSTACK_WEBHOOK_IPS` | *(empty)* |  |
| `RECURRING_MAX_FAILURES` | `3` | How many consecutive failed charges pause a regular gift. Then the donor is told, with a link to start it again — it is not retried for ever. |
| `DONATION_MIN_PESEWAS` | `500` | Money handling. Amounts are integer pesewas everywhere. The currency is GHS, fixed in App\Support\Money and in every Paystack call; it is not a setting. GH₵ 5.00 |
| `DONATION_MAX_PESEWAS` | `10000000` | GH₵ 100,000.00 |
| `DONATION_PRESETS_PESEWAS` | `"5000,10000,25000,50000,100000"` |  |
| `DONATION_ALLOW_FEE_COVER` | `true` |  |
| `PAYSTACK_FEE_PERCENT` | `1.95` | Paystack Ghana pricing — verify against your signed merchant agreement before go-live. |
| `PAYSTACK_FEE_CAP_PESEWAS` | `10000` | GH₵ 100.00 cap |
| `PAYSTACK_FEE_FLAT_PESEWAS` | `0` | a fixed fee per transaction, if the rate card has one |
| `PAYMENT_WEBHOOK_MAX_ATTEMPTS` | `5` | A webhook that keeps failing is retried this many times (with backoff) before it lands in failed_jobs for a person. |
| `PAYMENT_RECONCILIATION_ENABLED` | `true` | The reconciliation sweep (scghf:reconcile-payments): on/off, how far back it looks for settled-but-unrecorded payments, and after how many minutes a pending payment nobody completed is marked abandoned. |
| `PAYMENT_RECONCILIATION_LOOKBACK_DAYS` | `7` |  |
| `PAYMENT_ABANDON_AFTER_MINUTES` | `60` |  |

### Security

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `HONEYPOT_ENABLED` | `true` | A hidden field plus a minimum fill time, on the registration form and every other public form as they land. It stops the volume bots, which are most of them, without a CAPTCHA — and a CAPTCHA on a donation site is a wall in front of the people least able to get over it. ⚠ These names are spatie/laravel-honeypot's own. HONEYPOT_FIELD_NAME and HONEYPOT_VALID_FROM_FIELD were documented here since Phase 2 and read by nothing: the package looks for HONEYPOT_NAME and HONEYPOT_VALID_FROM, so the custom field names were silently ignored and the defaults `my_name` and `valid_from` were live. Harmless until now, and exactly the kind of key the .env rule in CLAUDE.md exists to catch. |
| `HONEYPOT_NAME` | `scghf_hp` |  |
| `HONEYPOT_VALID_FROM` | `scghf_vf` |  |
| `TURNSTILE_SITE_KEY` | *(empty)* | Cloudflare Turnstile on the donation form — the one form that is a card-testing target. Blank keys = no widget and no check; the honeypot and the throttle still stand. Free at dash.cloudflare.com → Turnstile; the "managed" widget type shows a checkbox only when it must. Test keys that always pass: site 1x00000000000000000000AA, secret 1x0000000000000000000000000000000AA. |
| `TURNSTILE_SECRET_KEY` | *(empty)* |  |
| `TURNSTILE_VERIFY_URL` | `https://challenges.cloudflare.com/turnstile/v0/siteverify` | Only change this if Cloudflare moves the verification endpoint. |
| `HONEYPOT_SECONDS` | `2` | Two seconds. A person filling in a registration form takes longer than that even if they paste everything; a bot posts in milliseconds. |
| `HONEYPOT_RANDOMIZE` | `true` | Appends a random string to the field name, so a bot cannot learn the name once and skip it everywhere. |
| `ADMIN_PATH` | `scghf-office` | Read by config/admin.php. /admin is the first path a scanner tries and /administrator is the second — moving it does not protect the panel on its own, but it takes the site out of the automated sweeps that go looking for a login form to spray credentials at. Deliberately NOT a generic word: manage, backend, panel, console, dashboard and office are all in the same wordlists admin is. A hyphenated organisation name is not. Change it once, before staff have bookmarks — it invalidates every bookmarked admin URL. An empty value is refused and falls back, because ADMIN_PATH= would otherwise mount the panel at / and turn the home page into a login form on deploy. [Decide] any non-obvious path |
| `ADMIN_2FA_REQUIRED` | `true` | ⚠ Setting this false does not relax a policy — it removes the only barrier between a leaked password and every record the foundation holds. It exists so a locked-out administrator can be recovered by somebody with server access, and should be switched back the same hour. every admin role — not optional |
| `ADMIN_SESSION_TIMEOUT` | `60` | Minutes of inactivity. Shorter than SESSION_LIFETIME on purpose: an admin panel left open on a shared office machine is a different exposure from a donor's phone. |
| `ADMIN_ABSOLUTE_TIMEOUT` | `720` | Minutes since sign-in after which a staff session ends whatever it is doing. |
| `ADMIN_SINGLE_SESSION` | `false` | One live session per staff account: signing in anywhere ends the others. |
| `ADMIN_IP_ALLOWLIST` | *(empty)* | Addresses or CIDR ranges that may reach the panel. Empty = anywhere. With Cloudflare in front, set TRUSTED_PROXIES or every visitor is Cloudflare. |
| `TRUSTED_PROXIES` | *(empty)* | Proxies whose X-Forwarded-For is believed. "*" behind Cloudflare, after the .htaccess rule that refuses connections not from Cloudflare's ranges. |
| `RATE_LIMIT_LOGIN` | `5` | Read by config/security.php. Login is limited per email AND per IP together — per email alone lets anybody lock a named administrator out of their own account. |
| `RATE_LIMIT_PASSWORD_RESET` | `3` |  |
| `RATE_LIMIT_REGISTRATION` | `3` |  |
| `RATE_LIMIT_CONTACT` | `3` |  |
| `RATE_LIMIT_DONATION` | `10` | loosest on purpose — a failed MoMo prompt is retried |
| `RATE_LIMIT_API` | `60` |  |
| `LOGIN_LOCKOUT_SECONDS` | `900` |  |
| `FORCE_HTTPS` | `false` | true in staging + production |
| `HSTS_MAX_AGE` | `0` | HSTS, in seconds; 0 = not sent. Only once EVERY subdomain is https — it commits them all. 31536000 is a year. See docs/PHASE-12-SECURITY.md. |

### Public donor accounts

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `PASSWORD_MIN_LENGTH` | `12` | Twelve, not eight. Length is what resists an offline attack; "one capital, one digit, one symbol" mostly produces Password1! on every site the person uses. NIST SP 800-63B has preferred length over composition since 2017. |
| `PASSWORD_CHECK_COMPROMISED` | `true` | Checks new passwords against Have I Been Pwned by k-anonymity — only the first five characters of the SHA-1 leave this server. Laravel fails OPEN when the API is unreachable, so a donor is never blocked from registering by somebody else's outage. Set false only if outbound HTTPS from the host is genuinely blocked. |
| `ACCOUNT_REGISTRATION_OPEN` | `true` | Whether the public may create an account at all. A switch rather than a deploy, because the realistic reason to need it is a registration-spam wave at 2am. When false the form is a 404, not a 403 — a 403 announces there is something here worth coming back for. |
| `ACCOUNT_ALERT_NEW_DEVICE` | `true` | Email the account holder when their account is signed into from a device it has not been used on before. Never fires on the first sign-in of a new account, when every device is new and the alert would mean nothing. |
| `ACCOUNT_LINK_LIFETIME` | `60` | Minutes a verification link stays usable. Password reset links use config/auth.php's own expiry instead, so the two cannot disagree. |

### Backups

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `BACKUP_ENABLED` | `true` | A backup on the same shared account is not a backup. It protects against a bad deploy and a bad migration, and NOT against losing the account — pulling a copy off the server is step 11 of the Phase 2 runbook, and it is a human step. ⚠ Every key in this block was documented from Phase 2 and read by NOTHING until Phase 5: config/backup.php did not exist and no schedule entry ran a backup, so the listener that records each run sat waiting for events nobody fired. They are all read now. |
| `BACKUP_DISK` | `backups` | a local disk OUTSIDE public/; prod may use s3 |
| `BACKUP_ARCHIVE_PASSWORD` | *(empty)* | encrypts the archive — donor data |

### Error monitoring

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `SENTRY_LARAVEL_DSN` | *(empty)* | Sentry (free tier: 5k errors/month). Empty = off; the in-app error reports in the admin still work. Read by config/sentry.php. No personal data is sent. |
| `SENTRY_ENVIRONMENT` | `"${APP_ENV}"` |  |
| `SENTRY_RELEASE` | `"${APP_RELEASE}"` |  |
| `SENTRY_TRACES_SAMPLE_RATE` | `0` | performance tracing off; errors only |
| `SENTRY_SEND_DEFAULT_PII` | `false` |  |
| `BACKUP_NOTIFICATION_EMAIL` | *(empty)* | {{EMAIL_GENERAL}} — failures only, never successes |
| `BACKUP_KEEP_DAILY` | `14` |  |
| `BACKUP_KEEP_WEEKLY` | `8` |  |
| `BACKUP_KEEP_MONTHLY` | `6` |  |
| `BACKUP_MAX_MEGABYTES` | `3000` | cPanel Backup Usage is a separate, smaller allowance |

### SEO & indexing

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `VISITOR_STATS_ENABLED` | `true` | Whether search engines may index the site is the `seo.allow_indexing` SETTING (Settings → SEO), seeded false, and it drives robots.txt, the X-Robots-Tag header and the Site Health row. Staging leaves it false. There is no .env key for it any more: the one documented since Phase 2 was read by nothing. Web analytics is a SETTING too (Settings → Analytics: none, Plausible, Umami or GA4 in consent mode — Phase 13). The built-in visitor statistics behind Finance → Site analytics are aggregate daily counts with nothing about the person; this is the one switch that turns that counting off entirely. |

### Feature flags

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `FEATURE_SHOP` | `true` | Modules deferred in PHASE-1-BLUEPRINT.md §12c ship behind flags, off by default, so partially-built features can never leak into production. |
| `FEATURE_DONATIONS` | `true` |  |
| `FEATURE_RECURRING_GIVING` | `true` |  |
| `FEATURE_MOBILE_MONEY` | `true` |  |
| `FEATURE_VOLUNTEERS` | `true` |  |
| `FEATURE_EVENTS` | `true` |  |
| `FEATURE_EVENT_TICKETING` | `false` | deferred — §12c #12 |
| `FEATURE_P2P_FUNDRAISING` | `false` | deferred — §12c #11 |
| `FEATURE_SPONSORSHIP` | `true` |  |
| `FEATURE_BLOG_COMMENTS` | `false` |  |
| `FEATURE_PRAYER_REQUESTS` | `true` |  |
| `FEATURE_MULTILINGUAL` | `false` | scaffolding built, English at launch |
| `FEATURE_SITE_SEARCH` | `true` | built in Phase 6; the /search route is gated on it |
| `FEATURE_DARK_MODE` | `true` |  |
| `FEATURE_PWA_OFFLINE` | `true` | Built (Wave 1): the web manifest, home-screen icons rendered from the logo, a service worker that precaches the shell and shows /offline (with the Mobile Money number) when the connection drops. Off: no manifest is linked and an installed worker unregisters itself on the next visit. |

### Delivery webhook secrets

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `MNOTIFY_WEBHOOK_SECRET` | *(empty)* | Where bounces, complaints and delivery reports come back in. A provider with NO secret verifies as FALSE, never true — the shortcut "no secret, so skip the check" turns an unconfigured endpoint into an open one that anybody can use to suppress any address they can guess. |
| `POSTMARK_WEBHOOK_SECRET` | *(empty)* |  |
| `MAILGUN_WEBHOOK_SECRET` | *(empty)* |  |
| `RESEND_WEBHOOK_SECRET` | *(empty)* |  |

### Audit archive

| Key | Default in `.env.example` | What it does |
|---|---|---|
| `AUDIT_ARCHIVE_AFTER_YEARS` | `2` | Whole CLOSED years are moved out of audit_logs into a compressed, verified file. Two years stay live because that is the window in which anybody actually searches them. `local` is storage/app — outside the web root, which matters: an audit archive behind a guessable URL is a worse leak than the table. |
| `AUDIT_ARCHIVE_DISK` | `local` |  |

## 2. The cross-check

Keys the code reads via `env()` that `.env.example` does not document (framework and vendor defaults with their own config files are excluded):

- none ✔

Keys `.env.example` documents that no `env()` call in this repository reads (`HONEYPOT_*` are read by spatie/laravel-honeypot's own config and are excluded):

- none ✔

## 3. The ones that matter most

| Key | Why it is dangerous to get wrong |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` and `false`, or the deploy script refuses. Debug pages show `.env` values |
| `APP_KEY` | rotating it invalidates every encrypted setting, cookie and signed URL; `APP_PREVIOUS_KEYS` exists for that |
| `PAYMENT_DRIVER`, `PAYSTACK_*` | `fake` takes no money; `sk_test_` on production is refused by the deploy script; the webhook secret must be the same account's |
| `PAYSTACK_WEBHOOK_SECRET` | empty = every webhook rejected = no donation is ever completed |
| `MAIL_*`, `RESEND_*` | receipts; `PHASE-10-EMAIL-DELIVERABILITY.md` |
| `SMS_DRIVER` + its keys | the provider refuses to boot in production with a driver chosen and no key |
| `ADMIN_PATH` | the panel's address; `/admin` is refused |
| `TRUSTED_PROXIES` | behind Cloudflare, without it every visitor is Cloudflare — rate limits and the IP allowlist stop working |
| `MEDIA_INODE_BUDGET` | the health row is only as honest as this number |
| `BACKUP_*` | off-server destination and the encryption password; a backup you cannot decrypt is not one |

