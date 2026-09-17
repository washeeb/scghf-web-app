# Data protection: Act 843, GDPR, and what the application does about them

## Why this document exists

The foundation holds names, phone numbers, addresses, giving histories,
photographs of children, safeguarding checks and disclosed convictions. In
Ghana that is governed by the **Data Protection Act, 2012 (Act 843)**; for
the donors abroad it is also the **GDPR** (EU/UK). This document says what
each requires, what the application already does, and what is still a
task for a person.

## Act 843 — the obligations, and where each is met

| Act 843 | What it asks | Where it is met |
|---|---|---|
| **s.17–18 Lawful processing** | A lawful basis for every processing of personal data. | See the table of bases below. |
| **s.18(2) Consent** | Freely given, specific, informed. | Every form asks separately for holding details, contact, marketing, photography; nothing pre-ticked; the wording shown is snapshotted onto the record with the time and IP (`consent_text`, `consent_at`, `consent_ip` on donors, subscribers, registrations, applications). Newsletter is double opt-in. |
| **s.19 Minimality** | Collect no more than the purpose needs. | Forms ask for what the flow uses; date of birth only for a police-check role; referees only for vulnerable-contact roles. The retention schedule deletes rather than keeps. |
| **s.20 Retention** | Keep no longer than needed. | `config/compliance.php` retention classes, enforced weekly by `scghf:retention` (dry-run by default; a person runs it). Schedule below. |
| **s.21 Purpose specification / s.24 further processing** | Use for the stated purpose only. | Marketing categories are separate from transactional; a receipt address is never a newsletter address; suppression scopes (`marketing` vs `all`). |
| **s.23 Notification (privacy notice)** | Tell the person what is collected and why. | The Privacy Policy page (seeded, trustee-published) and the per-form consent sentence. |
| **s.28 Security safeguards** | Appropriate technical and organisational measures. | Phase 12: encryption at rest for the most sensitive columns, 2FA, sessions, headers, audit trail, backups; `docs/PHASE-12-SECURITY.md`. |
| **s.31 Breach notification** | Notify the Commission and affected persons. | The incident runbook (`docs/PHASE-12-INCIDENT-RUNBOOK.md`, "data breach"). |
| **s.32–33 Access and correction** | A copy of one's data; correction. | *Your account → Your data → Download my data* (JSON, password-gated, audited). Correction: *Your details*. Requests by email: the same exporter can be run for any account by an administrator with `donors.view_pii` (see below). |
| **s.34 Erasure / objection** | Delete; stop processing for marketing. | *Delete my account* (password + confirmation; financial carve-out); unsubscribe and suppression for marketing. |
| **s.35–36 Processing of special data** | Stricter rules for religion, health, criminal record, children. | Disclosed convictions, safeguarding concerns, referees and next-of-kin are encrypted at rest; retention is shorter for medical documents; photographs of children need a named guardian's consent before they can be published. |
| **s.46–47 Registration** | Data controllers register with the **Data Protection Commission** and renew. | **A task for the trustees — see below.** |

### Registration with the Data Protection Commission

The foundation is a data controller and must register (Act 843 s.46;
Data Protection (Registration) Regulations). Steps:

1. Appoint a **Data Protection Supervisor** (s.58) — a named trustee or
   staff member. Record the name in the Privacy Policy page.
2. Register at **dataprotection.org.gh** (Registration → Data Controller).
   You will need: certificate of incorporation, the supervisor's details,
   the categories of data subjects (donors, volunteers, beneficiaries,
   website visitors, staff), the categories of data (this document's
   inventory), the purposes, the recipients (Paystack, Resend, the SMS
   gateway, InMotion Hosting as processor), and whether data leaves Ghana
   (it does: Paystack, Resend, the hosting server are outside Ghana).
3. Pay the fee for the foundation's size band and keep the certificate.
   **Renew every two years.** Put the renewal date in the shared calendar
   and in *Site settings → Compliance*.
4. Keep this document and the retention schedule as the record the
   Commission may ask for.

Until registered, the foundation is processing without registration,
which is an offence under s.56. This is the single most important action
item in Phase 12.

## Lawful basis for each processing

| Processing | Basis (Act 843 s.18 / GDPR art.6) | Notes |
|---|---|---|
| Taking a donation, issuing a receipt | Contract / legal obligation | The receipt is a tax record; it cannot be refused or erased inside the statutory period. |
| Regular giving | Contract | The donor set it up; the management link lets them end it. |
| Shop orders and delivery | Contract | |
| Email/SMS about *their* gift or order (transactional) | Contract / legitimate interest | Not marketing; suppression for `all` (hard bounce, complaint) still stops it. |
| Newsletter, appeals, SMS updates | **Consent** | Double opt-in; unsubscribe and preferences on every message. |
| Volunteer applications and safeguarding checks | Consent + legal obligation (child protection) + legitimate interest | The declaration is signed; checks are recorded against it. |
| Beneficiary records | Consent (of the person or guardian) + vital interests + legitimate interest | Photographs of children: explicit guardian consent, per image. |
| Event registrations | Contract (a place) + consent (photography, newsletter) | |
| Contact enquiries | Legitimate interest (replying) with consent recorded on the form | |
| Website analytics | **Consent** — and none is collected today | The cookie banner gates it before it loads if ever added. |
| Security logs, audit trail, login history | Legitimate interest / legal obligation | Retained per the schedule; visible to the account holder in their export. |
| Backups | Legal obligation (accounting) + legitimate interest | Off-server, encrypted archive; see the backup runbook. |

## GDPR, for donors outside Ghana

The foundation is not established in the EU/UK, but it offers a donation
page to people there, so GDPR art.3(2) applies to those donors. What that
adds on top of Act 843:

- **Art.13 notice** — the Privacy Policy must name the controller, the
  purposes and bases (the table above), retention (the schedule below),
  the rights, and that data is processed in Ghana and the USA (Paystack,
  Resend, hosting). A one-paragraph "international donors" section covers
  it.
- **Art.15–17 rights** — export and erasure are the same features as
  Act 843's. Response within one month.
- **Art.27 representative** — an organisation outside the EU that
  processes EU residents' data "regularly" must appoint a representative
  in the EU. For occasional donations this is arguably not triggered
  (art.27(2)(a)); **a trustee decision, with advice**, once EU donations
  are more than occasional.
- **Cookies (ePrivacy)** — the banner: essential only by default, nothing
  non-essential loads before consent, a preferences UI, the decision
  timestamped in the cookie.

## The retention schedule

From `config/compliance.php` (`retention.classes`), which is what
`scghf:retention` enforces. Legal, audit and investigation holds override
every line. The schedule is the code; this table is a rendering of it.

| Class | What it covers | Kept for | Counted from | Then |
|---|---|---|---|---|
| `beneficiary_application_declined` | Declined beneficiary application | 24 months | decision | delete |
| `beneficiary_application_withdrawn` | Incomplete or withdrawn application | 12 months | last activity | delete |
| `beneficiary_case_record` | Approved beneficiary case record | 6 years | case closed | de-identify |
| `beneficiary_sensitive_document` | Medical and other highly sensitive documents | 24 months | case closed | delete |
| `volunteer_application_declined` | Declined volunteer application | 12 months | decision | delete |
| `volunteer_application_withdrawn` | Incomplete or withdrawn volunteer application | 6 months | last activity | delete |
| `volunteer_record` | Volunteer record incl. safeguarding checks | 6 years | they left | de-identify |
| `prayer_request` | Prayer request | 12 months | received | delete |
| `event_registration` | Event registration | 24 months | event ended | delete |
| `communication_log` | Email, SMS and notification logs | 24 months | sent | delete |
| `financial_record` | Donations, orders, receipts, transactions | **6 years minimum** | created | retain — never on a schedule |
| `anonymised_statistics` | Aggregate figures with no person in them | indefinite | — | retain |

Tables not in a class and how they are handled:

| Table(s) | Handling |
|---|---|
| `users` | Kept while the account exists; scrubbed and soft-deleted on erasure; staff accounts kept (suspended) for the audit trail's causer references. |
| `donors` | Kept with the financial records; name/contact scrubbed on erasure. |
| `subscribers` | Deleted on unsubscribe + erasure; suppressed address kept. |
| `suppressions` | Kept — the address alone, so it cannot be re-added. |
| `contact_messages` | Resolved messages: 24 months from resolution (add to `communication_log` in a later phase if the inbox grows). |
| `login_histories`, `audit_logs` | Audit logs are archived yearly (`scghf:archive-audit-log`), hash-chained; login history 12 months. |
| `sessions`, `cache`, `jobs` | Operational; hours to days. |
| `media` | Until deleted by staff; a withdrawn image stays on disk with the reason; beneficiary photographs follow their case record. |

## Data subject requests — the procedure

1. **Verify the person.** A request from the account's email address, or
   through the signed-in account, is enough. A request about somebody
   else (a parent for a child, a solicitor) needs evidence of authority.
2. **Access:** the person uses *Your data → Download my data*. For
   somebody with no account, an administrator with `donors.view_pii`
   runs `php artisan scghf:export-data {email}` (see below) and sends the
   file by a channel the person controls — never to an address that
   merely claims to be them.
3. **Erasure:** the person uses *Delete my account*. Without an account:
   the Subscribers screen's *Erase*, and the Donors screen's *Anonymise*
   for the giving profile — financial records stay under the carve-out.
4. **Log it.** Every export and erasure is in the audit trail
   (`privacy.exported`, `erasure.requested`, `erasure.completed`).
5. **Within 30 days.** Act 843 does not fix a period; GDPR says one month.

> `scghf:export-data` is the same exporter the account page uses, for
> people without an account. It is listed here as the procedure and
> built in Phase 12 Module 4 alongside the restore command.

## Personal data inventory (for the DPC registration form)

| Category of person | Data | Source | Recipients outside the foundation |
|---|---|---|---|
| Donors | name, email, phone, address, giving history, last-4 and card brand, MoMo network | donation form, Paystack webhook | Paystack (payment), Resend (email), SMS gateway, InMotion (hosting) |
| Shop customers | as donors + delivery address, GhanaPost GPS | checkout | as above + courier (address only) |
| Newsletter subscribers | email, name, topics, consent evidence | signup forms | Resend |
| Event attendees | name, email, phone, dietary/accessibility needs, photography consent | registration | Resend, SMS gateway |
| Volunteers and applicants | full application incl. DOB, next of kin, referees, disclosed convictions, police clearance number, hours, shifts | application form, staff | none (encrypted at rest) |
| Beneficiaries | case records, documents, photographs, consents | staff, case work | none; photographs on the website only with consent |
| Staff | name, email, phone, roles, login history, 2FA secret | admin | Resend |
| Website visitors | IP address in logs and consent records; theme and consent cookies | browser | Cloudflare (if used), InMotion |

## Beneficiary photographs

- A photograph is flagged *shows a person* / *shows a child* in the media
  library. With either flag, it **cannot be published** until a valid
  consent record exists on its Consent tab — for a child, one given by a
  named parent or guardian, with the signed form attached as evidence.
- **Withdrawal**: *Withdraw this image* on the media record, or *Revoke*
  on the consent, takes it off every page, gallery, card and social
  preview on the next request. Nothing is deleted; the reason is kept and
  the action audited.
- A gallery's *has consent* flag (Phase 5) still governs the gallery as a
  whole; the per-image rule sits under it.
- The Safeguarding page (seeded) is the public policy; this is its
  mechanism.
