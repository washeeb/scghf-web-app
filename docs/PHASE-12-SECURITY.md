# Security: the OWASP review, the controls, and the procedures

Companions: `PHASE-12-PCI-DSS-SAQ-A.md` (payments),
`PHASE-12-DATA-PROTECTION.md` (Act 843 / GDPR),
`PHASE-12-INFRASTRUCTURE.md` (Cloudflare, backups, monitoring, incidents).

## OWASP Top 10 (2021) — findings and what was done

| # | Risk | Findings in this codebase | Fixed / in place |
|---|---|---|---|
| A01 | **Broken access control** | Every model has a policy and `PolicyCoverageTest` fails if one is missing. Found: `users.*` permissions seeded with **no screen** (staff accounts could only be made from a terminal); `consents.*` protecting nothing; `queue.manage`, `logs.*`, `messages.*` (fixed in Phase 10). | Users resource (Phase 12); consents on media; every action audited. Admin panel behind a non-obvious path, 2FA, optional IP allowlist. Signed URLs for receipts, tickets, order links, unsubscribe. |
| A02 | **Cryptographic failures** | Sensitive columns (convictions, referees, next of kin, police clearance numbers, safeguarding concerns) were **plaintext**. `APP_KEY` handled correctly; TOTP secrets already encrypted. | `encrypted` casts + `scghf:encrypt-at-rest`. HTTPS forced; HSTS available. Backup archives password-protected (`BACKUP_ARCHIVE_PASSWORD` — **must be set** before the first production backup). |
| A03 | **Injection** | All queries through Eloquent/query builder; the 59 `whereRaw`/`DB::raw` uses are constants or bound. **Stored XSS**: every rich-text field printed with `{!! !!}` on the strength of a comment claiming input sanitisation that did not exist. | `App\Support\Html::clean()` / `@clean` at every CMS output site; allowlist of tags, no scripts, no event handlers, no `javascript:`, images only from this origin. CSP nonce as the second line. |
| A04 | **Insecure design** | Money as integer pesewas; webhook as the only truth; two-person rules on refunds, campaigns, broadcasts, volunteer approval; safeguarding gate refuses in code. | Unchanged; the design holds. Added: anomaly alerts, restore test, absolute session timeout. |
| A05 | **Security misconfiguration** | `.htaccess` headers only (files served by Apache) — PHP responses had none; CSP report-only with `unsafe-inline`/`unsafe-eval`; `APP_DEBUG` guarded by preflight; `X-Powered-By` unset. Eleven `.env` keys read by nothing (Phase 10 sweep). | `SecurityHeaders` middleware on every response; enforced nonce CSP on the public site; `.htaccess` aligned with `setifempty`; Site Health rows for environment, https, mail, error monitoring, restore test. |
| A06 | **Vulnerable components** | No automated audit. Dependabot existed. | `composer audit` and `npm audit --audit-level=high` in CI; monthly routine below. |
| A07 | **Identification & authentication failures** | Login throttled per email+IP, lockout, compromised-password check, 2FA enforced for staff, remember-token rotation on password change, sessions invalidated on password/email change. Missing: absolute timeout, single-session, sign-out-everywhere, IP allowlist. | All four added. Staff accounts: random unknown password + reset link on creation; 2FA reset requires a written verification note and is audited as critical. |
| A08 | **Software & data integrity** | Audit log hash-chained (`scghf:verify-audit-log`); deploy verifies Filament assets and refuses a tracked `.env`; `composer.lock` committed. | Unchanged. Sentry release tagging via `APP_RELEASE`. |
| A09 | **Logging & monitoring failures** | Audit trail comprehensive; in-app error reports; but a payment mismatch was a log line nobody reads and no external monitoring existed. | `AnomalyAlerts` email; Sentry; UptimeRobot on `/up`; CSP reports logged. |
| A10 | **SSRF** | Outbound HTTP only to configured hosts (Paystack, SMS gateways, Resend, Turnstile). No user-supplied URLs are fetched server-side. `directions_url` on offices is rendered as a link, never fetched. | Unchanged. |

## File uploads

- MIME sniffed from bytes, not the extension; allowlist of image types
  and PDF; SVG refused outright; size caps (`MEDIA_MAX_IMAGE_MB`,
  `MEDIA_MAX_DOCUMENT_MB`); randomised storage names (spatie); EXIF/GPS
  stripped before an image can be published (`StripMediaMetadata`,
  `MediaLibraryTest`).
- Private files (receipts, downloads, packing slips, invoices) live
  outside `public/` and are served by controllers behind signed URLs or
  permissions.
- **ClamAV is not available on InMotion shared hosting.** Uploads are
  staff-only (media library) or strictly typed (a CV on a volunteer
  application, PDF/image only). This is a documented gap, not a stub: a
  scanner would need a VPS or a third-party scanning API, and the
  trustees should decide whether the risk (a staff member uploading an
  infected PDF that another staff member downloads) warrants the cost.

## Headers and CSP — what is sent

`App\Http\Middleware\SecurityHeaders`, on the public site:

```
Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none';
  frame-ancestors 'self'; form-action 'self' https://checkout.paystack.com;
  script-src 'self' 'nonce-…' https://js.paystack.co https://challenges.cloudflare.com;
  style-src 'self' 'nonce-…'; style-src-attr 'unsafe-inline';
  img-src 'self' https: data: blob:; font-src 'self' data:;
  connect-src 'self' https://api.paystack.co https://challenges.cloudflare.com;
  frame-src 'self' https://checkout.paystack.com https://challenges.cloudflare.com;
  report-uri /csp-report; upgrade-insecure-requests
X-Content-Type-Options: nosniff · X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin · Cross-Origin-Opener-Policy: same-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self), …
Strict-Transport-Security: max-age=<HSTS_MAX_AGE>; includeSubDomains   (when set, https only)
```

- The public site runs **no Alpine and no Livewire**, so there is no
  `unsafe-eval` and no `unsafe-inline` for scripts. Inline `style=""`
  attributes carry per-record colours, hence `style-src-attr`.
- The **admin panel** gets the same headers but its CSP is
  **report-only** with `unsafe-inline` and `unsafe-eval`: Filament and
  Alpine need both. A tightening is a Filament-version question.
- `public/.htaccess` carries the same headers with `setifempty`, so a
  file Apache serves without PHP gets them and nothing is doubled. Its
  CSP has no nonce and is only ever applied to non-HTML files.
- Violations POST to `/csp-report` (CSRF-exempt, throttled) and are
  logged at warning.

**To enable HSTS:** confirm https on the apex and *every* subdomain
(`staging.`, `mail.`, `cpanel.`), then set `HSTS_MAX_AGE=300`, watch a
day, then `31536000`. Do not add `preload` until the year has passed.

## Sessions and the admin door

| Control | Where | Default |
|---|---|---|
| Secure, http-only, SameSite=Lax cookies | `config/session.php` | on |
| Session regenerated on login | Laravel | on |
| Idle timeout (staff) | `ADMIN_SESSION_TIMEOUT` | 60 min |
| Absolute timeout (staff) | `ADMIN_ABSOLUTE_TIMEOUT` | 12 h |
| Single session per staff account | `ADMIN_SINGLE_SESSION` | off |
| Sign out everywhere | account security page (donor); Users → Sign out everywhere (staff) | — |
| IP allowlist | `ADMIN_IP_ALLOWLIST` (CIDRs) | empty = anywhere |
| Trusted proxies | `TRUSTED_PROXIES` | empty; `*` behind Cloudflare once .htaccess restricts to its ranges |
| Non-obvious admin path | `ADMIN_PATH` | `scghf-office` — change it |
| 2FA enforced | `ADMIN_2FA_REQUIRED` | true |
| Failed-login lockout | `RATE_LIMIT_LOGIN`, `LOGIN_LOCKOUT_SECONDS` | 5 / 15 min |
| Activity logging | audit trail + spatie activitylog + login history | on |

## Rate limits

`config/security.php`: login 5/min per email+IP, password reset 3, registration 3,
contact 3, donation 10 (a failed MoMo prompt is retried), API 60; plus
honeypot + timing on every public form, Turnstile on the donation form
when keys are set, `throttle:30,1` on `/csp-report`, `5,60` on data export,
`3,60` on account deletion. Webhooks are not rate-limited (Paystack retries
on non-200) but are signature-verified and IP-allowlisted.

## Secrets

**Nothing secret is in the repository.** `.env` is git-ignored and the
deploy refuses if it is ever tracked. `.env.example` documents every key
with a placeholder, and every documented key is read by something.

### Rotation procedure (routine: annually, or on any staff departure with server access)

| Secret | How to rotate | Then |
|---|---|---|
| `APP_KEY` | **Do not.** It encrypts 2FA secrets, encrypted columns and sessions; rotating it destroys them. If it must change, decrypt every `encrypted` column and 2FA secret first (write a command; there is none because it should never be needed). | — |
| `PAYSTACK_SECRET_KEY` / `PUBLIC_KEY` | Paystack dashboard → Settings → API Keys → Generate new. Update `.env`, `php artisan config:cache`. | Old key stops within minutes; regular gifts keep working (authorization codes are per account, not per key). |
| `PAYSTACK_WEBHOOK_SECRET` | Same as the secret key on Paystack (it signs with the secret key). | — |
| `RESEND_API_KEY` | Resend → API Keys → create new (sending access only), update `.env`, delete the old. | Watch the outbox for failures for an hour. |
| `RESEND_WEBHOOK_SECRET` | Resend → Webhooks → rotate secret; Svix sends both signatures during the overlap and the verifier accepts either. | — |
| SMS gateway keys | Provider dashboard; update `.env`. | Send a test from *SMS templates → Send a test*. |
| `BACKUP_ARCHIVE_PASSWORD` | Change in `.env`; **old backups still need the old password** — record it in the password manager with the date. | Run `backup:run` so a backup exists under the new one. |
| `SENTRY_LARAVEL_DSN` | Sentry → Project → Client Keys. | — |
| Database password | cPanel → MySQL Databases → change; update `.env`; `config:cache`. | The queue worker picks it up on its next minute. |
| SSH deploy key | New keypair; public key in cPanel → SSH Access; private key in the GitHub secret. | Run a deploy. |

### If the Paystack secret key leaks

1. **Generate a new key in the Paystack dashboard immediately** (this
   revokes the old one). Update `.env`, `php artisan config:cache`.
2. Check Paystack → Transactions and → Transfers for anything you did not
   make in the window. A secret key can initiate transfers *out* of the
   Paystack balance if transfers are enabled — check whether they are,
   and turn them off if the foundation does not use them.
3. Check Paystack → Settings → Webhooks that the URL has not been changed.
4. Check `payment_webhook_events` for events with `signature_valid = 0`
   (forged attempts) around the time.
5. Tell Paystack support what happened and when.
6. Find how it leaked (a committed `.env`? a screenshot? a shared
   laptop?) and close that — the audit trail and `git log -p -S` help.
7. Record it as an incident (`PHASE-12-INFRASTRUCTURE.md`, incident log).

Card data was never at risk: the key never touched a card number.

## What is deliberately not done

- **Virus scanning** — no ClamAV on shared hosting (above).
- **API rate limits by token** — there is no public API in this phase;
  `RATE_LIMIT_API` guards the future one.
- **Content Security Policy on the admin panel enforced** — Filament needs
  `unsafe-eval`; report-only until that changes upstream.
- **Web application firewall** — Cloudflare's, when the DNS moves there
  (`PHASE-12-INFRASTRUCTURE.md`); not something the application can do.
