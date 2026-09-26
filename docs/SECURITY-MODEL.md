# Security model — what is protected, from whom, and what to do when it fails

The findings and the header-by-header detail are `PHASE-12-SECURITY.md`;
the card-data posture is `PHASE-12-PCI-DSS-SAQ-A.md`; personal data is
`PHASE-12-DATA-PROTECTION.md`; the **incident runbook** is
`PHASE-12-INFRASTRUCTURE.md` §4 and is summarised at the end of this
page. Reporting a vulnerability: `../SECURITY.md`.

## 1. What is at stake

Three things, in order: **money** (a donation credited that was not paid,
or paid and not credited, or refunded by one person alone), **people's
data** (donors' contact details and giving history; beneficiaries and
children, whose photographs and case records are the most sensitive thing
this application holds), and **the foundation's name** (a defaced page, a
newsletter sent to everybody by somebody who should not).

## 2. Who can do what

| Actor | Gets | Never gets |
|---|---|---|
| Visitor | public pages, forms with honeypot + throttles + optional Turnstile, the donate and checkout flows | any page of another visitor's (the page cache is anonymous-only and varies on nothing personal) |
| Donor account | their own gifts, receipts, subscription, data export, account deletion | the admin panel (`type = donor` cannot open it whatever their permissions) |
| Staff | the admin panel at `ADMIN_PATH`, after mandatory TOTP, from allowed addresses if set; **exactly the permissions their role grants** (`AdminAccessMatrixTest` walks every URL for every role) | the Paystack secret key (Super Admin only); rewriting the permission matrix (Super Admin only); approving their own refund |
| Super Admin | everything | nothing is above the audit log |
| Paystack | `POST /webhooks/paystack` | anything without a valid HMAC-SHA512 over the raw body; a second delivery of the same `event_id` |
| Mail provider | `POST /webhooks/…` for bounces, Svix-signed | the same |

Permissions are strings (`donations.view`, `payments.replay_webhook`,
`consents.manage`) checked by policies that deny by default; roles are
only bundles of them (`database/seeders/RoleAndPermissionSeeder.php`).

## 3. The controls, by layer

**Transport and headers.** HTTPS forced; HSTS (`HSTS_MAX_AGE`); a
per-request **nonce CSP enforced on the public site** (no inline scripts
without the nonce, no `eval`), report-only in the admin because Filament
needs inline scripts; `X-Frame-Options`, `X-Content-Type-Options`,
`Referrer-Policy`, `Permissions-Policy`; the same headers in `.htaccess`
for files PHP never sees; `TRUSTED_PROXIES` for Cloudflare.

**Input.** Form requests validate everything; rich text from the CMS is
sanitised on output (`@clean`); uploads are typed, sized, image-decoded
and re-encoded with EXIF/GPS stripped before the file is reachable; SVGs
are refused; file names are ULIDs.

**Sessions and the admin door.** Encrypted cookies (except the two the
browser writes); `SameSite=Lax`; mandatory TOTP with recovery codes for
staff; an absolute session timeout and optional single-session for the
admin; sign-out-everywhere; sign-in, failure, lockout and new-device
events recorded (`login_histories`) and emailed; an IP allowlist if set;
rate limits on sign-in, forms, the CSP report endpoint, data export.

**Money.** `PAYMENTS.md`: raw-body HMAC, `hash_equals`, store before
parse, unique `event_id`, amount and currency re-verified against what we
expected, final states never reopened, two-person refunds, the anomaly
alerts, never a card number anywhere (allow-listed authorisation fields
only; the scrubber on every stored payload).

**Data.** Act 843 lawful bases and retention classes with holds and a
log; consent records for photographs, gating publication and withdrawal
everywhere at once; `encrypted` casts on the safeguarding columns and,
since Wave 2, on the beneficiary case record — ID number, health, bank
and MoMo details, next of kin, household, narrative, notes — with a blind
HMAC index so an ID number can be matched without being searchable
(`scghf:encrypt-at-rest` for existing rows); case screens generated from
one field map with visibility by relationship to the case, every open
audited, documents on a private disk behind five-minute signed links, one
export held by one permission never granted by wildcard; export-my-data and
delete-my-account with the statutory carve-out for financial records;
suppression lists that are never swept; the cookie notice gating
non-essential scripts.

**Audit.** A hash-chained, append-only `audit_logs` (`scghf:verify-audit-log`
daily; closed years archived and verified), spatie activity log on
models, every admin action recorded with who and from where.

**Supply chain.** `composer audit` and `npm audit` fail CI; Dependabot;
the monthly patch routine; secrets never in the repository (CI greps for
live keys and private keys on every run); `serializable_classes` false on
the cache.

**Infrastructure.** Off-server encrypted backups nightly with a monthly
restore test that restores; a pre-deploy database dump; Sentry; uptime
monitoring on `/up`; `APP_DEBUG=false` and test keys refused by the
deploy script in production.

## 4. What is deliberately not done

- No WAF of our own: Cloudflare's free tier in front is the recommendation
  (`PHASE-12-INFRASTRUCTURE.md` §2).
- No client-side encryption of donor data: the threat model is the host and
  the application, not the browser.
- No password rules beyond length + breach check
  (`PASSWORD_CHECK_COMPROMISED`): composition rules lower entropy in practice.
- No CAPTCHA by default: the honeypot, throttles and (optional) Turnstile
  on the donate form; CAPTCHAs are a wall on a 3G connection.

## 5. Incident response — the short form

The full runbook, with the incident log template, is
`PHASE-12-INFRASTRUCTURE.md` §4. Print it.

1. **Note the time. One person leads. Write everything down.**
2. **Site down** → `/up` from mobile data → cPanel suspension/quota →
   logs → rollback if it followed a deploy → database repair → Cloudflare
   status.
3. **Payment gateway down** → Paystack status → announcement bar with the
   MoMo/bank details (`/give`) → nothing on our side changes.
4. **A key or secret leaked** (`.env` in a screenshot, a key in a commit)
   → rotate it at the source **first** (Paystack dashboard, Resend, the SMS
   provider, `php artisan key:generate` with `APP_PREVIOUS_KEYS`), then
   update `shared/.env`, `config:cache`, and check the audit log and
   `login_histories` for use in the window. Paystack keys: the playbook in
   `PHASE-12-SECURITY.md` §Secrets.
5. **Suspected account compromise** → *Staff accounts → Suspend* (kills
   the session), reset 2FA, read the audit log for that user, rotate
   anything they could read.
6. **Data breach** (personal data seen by somebody who should not) → the
   Data Protection Commission notification duty and the affected people:
   `PHASE-12-INFRASTRUCTURE.md` §4 "Data breach" and the s.31 duty in `PHASE-12-DATA-PROTECTION.md`, within the statutory window.
7. **Afterwards**: the incident log, the cause, the fix with a test, and
   `CHANGELOG.md`.
