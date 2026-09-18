# Design note — beneficiary case management (roadmap item 1.7)

**Status: design only. Nothing in this note is built.** It is the paper the
safeguarding lead and the trustees are asked to read, correct and sign off
before Wave 2 starts, because the build is three to four weeks and the
mistakes it can make are the worst ones this application could make.

Written 18 September 2026, at the end of Wave 1 (`docs/ROADMAP.md` §1.7).

---

## 1. Why this exists

The foundation helps named people: a widow given a starter kit, a child
whose school fees were paid, a patient whose treatment was funded. Every one
of those is a case — an application, a decision, money, documents, an
outcome — and today the case lives on paper, in WhatsApp messages and in the
programme officer's memory. The database has held a complete schema for
cases since Phase 3 (`beneficiaries`, `beneficiary_documents`,
`beneficiary_impact_records`, `consents`, `payouts`) and the retention,
audit and disclosure rules since Phases 11–12. **There is no admin screen.**
The rules are real; the thing they protect does not yet exist.

This is also the most dangerous feature on the roadmap. A child's medical
note visible to the wrong role is a safeguarding failure, a breach of the
Data Protection Act, 2012 (Act 843), and the kind of story that ends a
charity. So the design is written down first, and the first question in
every section is *who may see this*.

## 2. What already exists (and what the design may rely on)

| Piece | Where | State |
|---|---|---|
| Case record with reference, status, assistance, outcome, case worker | `beneficiaries` table, `App\Models\Beneficiary` | Built. Statuses: draft → submitted → under_review → approved / declined / withdrawn → closed. `approve()`, `decline()`, `withdraw()`, `close()`, `deIdentify()` exist. |
| Documents (medical, financial, identity, school, referral, other) | `beneficiary_documents`, `App\Models\BeneficiaryDocument` | Built. `medical` and `identity` are always sensitive; a sensitive document's retention is 24 months from case close, the case record's is 72. |
| Consent for photographs, stories, name use, data processing; minors need a guardian | `consents`, `App\Models\Consent`, `HasConsents` | Built and used by the media library's publication gate. |
| Anonymous statistical record projected when a case closes | `beneficiary_impact_records`, `close()` | Built. Feeds the public impact figures through `DisclosureControl` (minimum group size). |
| Money paid out against a case | `payouts.beneficiary_id` | Built (Phase 7). The public spending log aggregates by category and folds small groups. |
| Permissions | `beneficiaries.view`, `beneficiaries.manage`, `consents.view`, `consents.manage` | Seeded. Held by **Programme Officer** and **Super Admin** only. Deliberately not in `content.*`. |
| Policy | `App\Policies\BeneficiaryPolicy` | Built. Hard delete refused for everyone; `download()` and `export()` are their own abilities. |
| Audit events | `config/system.php` → `beneficiary.viewed` (notice), `beneficiary.document_downloaded` (warning), `beneficiaries.exported` (critical) | Defined. **Nothing calls `beneficiary.viewed` yet**, because nothing views. |
| Retention | `config/compliance.php` classes `beneficiary_application_declined` (24 m), `beneficiary_application_withdrawn` (12 m), `beneficiary_case_record` (72 m, de-identify), `beneficiary_sensitive_document` (24 m, delete) | Built; `scghf:retention` runs them with legal holds. |
| Privacy element map | `Beneficiary::privacyElements()` | Built; a test fails if a column is added without a classification. |
| Media sanitising | `ImageSanitiser` strips EXIF/GPS on upload | Built. |

## 3. What does **not** exist, and must be built or decided before the screens

1. **Encryption at rest.** `ghana_card_number`, `medical_notes`,
   `bank_account`, `momo_number`, `case_notes`, `application_narrative`,
   `household_details`, `next_of_kin_*` are plain `text`/`varchar` columns.
   Phase 12 encrypted the volunteer equivalents (`safeguarding_checks.reference`,
   `volunteer_applications.next_of_kin_*`) and left these because no screen
   wrote them. The screens must not ship before these columns are
   `encrypted` casts with a widening migration, and searching by Ghana Card
   number then needs a blind index (an HMAC of the normalised number in its
   own column), because an encrypted column cannot be searched.
2. **The read audit.** `beneficiary.viewed` must be recorded by the view
   page and the edit page on every load — one row per person per case per
   session is the proposed granularity (see §7) so the trail is complete
   without being noise.
3. **The intake form.** There is no public application form and this note
   proposes there should not be one (see §5.1).
4. **Field-level visibility.** Filament has no built-in "this field for this
   permission" — it is `->visible(fn () => $user->can(...))` per component,
   which is exactly the kind of thing that gets missed on the fortieth
   field. The design proposes a single field map (§4) that the form, the
   infolist and the table are all generated from, and a test that walks it.

## 4. The data list, and who sees what

The trustees are asked to agree this table. Every column on `beneficiaries`
is here (the privacy-element test guarantees nothing is missed). "See"
means on screen; "Edit" means in the form. Roles beyond the two that hold
the permissions today are listed so the trustees can decide whether to
create them (§8 Q3).

**Proposed tiers**

- **Tier A — case summary.** Enough to know a case exists and where it is
  in the process. No health, no money details, no ID numbers.
- **Tier B — case working.** What a case worker needs to do the job.
- **Tier C — special category and financial.** Health, religion, ID
  document, bank/MoMo. Only the case's own worker and the safeguarding lead.

| Field | Privacy element | Tier | Programme Officer (worker on the case) | Programme Officer (not on the case) | Safeguarding lead | Finance Officer | Auditor | Super Admin |
|---|---|---|---|---|---|---|---|---|
| `case_reference`, `status`, `division_id`, `project_id`, `focus_area_id`, `case_worker_id`, dates | case_reference, programme, division | A | see/edit | see | see | see (approved cases only) | see | see/edit |
| `full_name`, `other_names`, `gender`, `region`, `district`, `community` | name, gender, region, district, community | A | see/edit | see | see | see (approved only) | see | see/edit |
| `phone`, `email`, `address` | phone, email, address | B | see/edit | — | see | — | see | see/edit |
| `date_of_birth` (shown as age band to everyone but the worker) | date_of_birth | B | see/edit | age band | see | — | age band | see/edit |
| `next_of_kin_name`, `next_of_kin_phone` | next_of_kin | B | see/edit | — | see | — | — | see/edit |
| `household_details`, `school_or_employer` | household, school_employer | B | see/edit | — | see | — | — | see/edit |
| `application_narrative` | narrative | B | see/edit | — | see | — | see | see/edit |
| `case_notes` (append-only, dated, signed) | case_notes | B | see/append | — | see/append | — | see | see/append |
| `assistance`, `assisted_on`, `outcome` | assistance_amount, assistance_date, outcome | B | see/edit | see | see | see/edit (amount, date) | see | see/edit |
| `latitude`, `longitude` | geolocation | B | see/edit | — | see | — | — | see/edit |
| `religion` | religion (special category) | C | see/edit | — | see | — | — | see |
| `medical_notes` | medical (special category) | C | see/edit | — | see | — | — | see |
| `ghana_card_number` (masked to last 4 unless revealed, and the reveal is audited) | national_id | C | see/edit | — | see | — | — | see |
| `bank_account`, `momo_number` | bank_details | C | see/edit | — | see | see (to pay) | — | see |
| `photo_id`, `signature_id`, `id_document_id` | likeness, signature, id_document | C (id document); A (photo, if consented) | see/edit | photo only | see | — | — | see |
| `intake_ip` | device | — | — | — | see | — | see | see |
| Documents: `medical`, `identity` | sensitive | C | see/download | — | see/download | — | — | see/download |
| Documents: `financial` | — | B | see/download | — | see/download | see/download | see | see/download |
| Documents: `school`, `referral`, `other` | — | B | see/download | — | see/download | — | see | see/download |

**Notes on the table**

- "Worker on the case" is `beneficiaries.case_worker_id = user.id`. A
  Programme Officer who is not the worker sees Tier A only. This is the
  single biggest change from the seeded permissions, which today give every
  Programme Officer everything; it needs a per-record check in the policy
  (`view()` → Tier A; a new `viewSensitive()` → worker or safeguarding lead).
- The Super Admin column is deliberately "see" not "see/edit" on Tier C: the
  role exists to fix the system, not to work cases. If the trustees want the
  Super Admin locked out of Tier C entirely, the policy can do it — say so
  (§8 Q4).
- Finance sees a case only once it is `approved` and only what a payout
  needs: the name, the reference, the amount, the bank/MoMo detail. Not the
  narrative, never the medical note.
- The Auditor role reads, never writes, and never sees Tier C — an audit
  is of the process, not the person.
- The age band replaces the birth date for everyone but the worker because
  "girl, 14, Nsawam, sickle-cell" is an identification; "girl, 10–14,
  Eastern Region" is a statistic.

## 5. The screens

All under `/scghf-office/beneficiaries`, Filament resource, navigation
group **Programmes**, visible only with `beneficiaries.view`.

### 5.1 Intake — staff form, not a public form

The application is entered by a member of staff sitting with the
applicant (or from a paper form), as a **draft** that only its creator and
the safeguarding lead can see until it is submitted. The reasons for no
public form:

- A public form collects special-category data from a child or a vulnerable
  adult over the internet with nobody present to explain what is being
  asked. The consent it produces is not one the foundation could defend.
- The foundation's applicants largely do not have the means to fill one.
- Spam and fraud: a public route that creates rows in the most sensitive
  table is an attack surface for no benefit.

If the trustees want a public *enquiry* form ("I would like to apply"),
that is the contact form with a subject line — it already exists
(`/contact`) — and creates nothing in `beneficiaries`.

The intake form captures the data-processing consent at the top, not the
bottom: `Consent` `data_processing` with `granted_by_name`,
`granted_by_relationship`, `is_minor` and `guardian_name`, the physical
signed form uploaded as `evidence_media_id`. **The form does not save
without it.** Photograph/story/name-use consents are separate, optional,
and default to *not given*.

### 5.2 The list

Tier A columns only. Filters: status, division, project, case worker,
region. Search on `case_reference` and name. **No bulk actions of any
kind** — no bulk delete, no bulk export, no bulk status change. No
`SoftDeletingScope` bypass (`withTrashed` is not offered). Default sort:
last activity.

### 5.3 The case page (view)

One page, sections by tier, each section a Filament `Section` that is
`->visible()` by the ability for that tier. Sections:

1. Summary (A) — reference, status, worker, programme, dates, the timeline
   of status changes from the activity log.
2. The person (B) — contact, household, next of kin, narrative.
3. Sensitive (C) — collapsed by default, opening it is what records the
   `beneficiary.viewed` context `tier: C` (the page load records `tier: A/B`).
   Ghana Card masked, "Reveal" button audited separately.
4. Documents — a table of `beneficiary_documents` with type, sensitivity,
   uploader, date. **Download is a signed URL valid for five minutes**,
   through a controller that checks `download()` on the policy and records
   `beneficiary.document_downloaded`. Never a public disk path. Sensitive
   documents show their retention date ("removed 24 months after close").
5. Consents — the `consents` rows with a "record consent" action (requires
   the evidence upload) and a "revoke" action (requires a reason). A revoked
   photo consent must unpublish the media, which `HasConsents` and the
   media gate already enforce.
6. Money — payouts against this case (`payouts.beneficiary_id`), read-only
   here; payouts are created in Finance's own resource and linked.
7. Case notes — append-only. A note is a row with author and timestamp,
   never edited after saving; `case_notes` on the record becomes the
   rendered log (the column exists; the design adds a `beneficiary_notes`
   table so notes are individually attributable and individually
   retainable — **schema addition, Wave 2**).

### 5.4 Actions (the status machine)

`Submit` (draft → submitted, by the creator), `Start review`
(→ under_review), `Approve` (→ approved; requires `assistance` and
`assisted_on` empty or in the future), `Decline` (requires a reason; the
reason is the applicant's to see, so it goes in a letter template, not in
`case_notes`), `Withdraw`, `Close` (requires `outcome`; runs `close()`,
which projects the anonymous record). Each action is a Filament action with
a confirmation and each maps to the existing model method — the screens
add no new transitions.

**No delete button.** `forceDelete()` is `false` for every role and the
resource does not register `DeleteAction`. Closing a case is what starts
the retention clock; the retention runner does the rest.

### 5.5 Export

One action, on the list, for the **data-protection lead only** (a new
permission `beneficiaries.export`, §8 Q3; today `export()` maps to
`manage`, which is too broad for a CSV of children's names). Filtered to
the current list, Tier A columns only, recorded as `beneficiaries.exported`
at critical with the row count, and the file is generated on demand and
streamed — never written to disk.

### 5.6 What the public site shows

Nothing new. The impact page and the appeal spending log continue to read
`beneficiary_impact_records` and aggregated `payouts` through
`DisclosureControl`. A story or photograph on the public site continues to
require its consent row. **No page on the public site ever reads
`beneficiaries` directly**, and a test should assert that no public
controller or view references the model.

## 6. Access matrix — the test that must exist before the first real record

`AdminAccessMatrixTest` (Phase 14) walks every role against every resource
with the policy as the oracle. This feature extends it with a **per-field
matrix**: for each role and each of {worker, not-worker, safeguarding lead},
render the case page and assert, for every field in §4, that it is present
or absent as the table says. The table in §4 *is* the fixture — encode it
as a PHP array once, generate the form/infolist/table from it (§3.4) and
test against the same array, so the code cannot drift from the decision.

Also required before go-live: the audit test (every view records
`beneficiary.viewed` with the tier; every download records
`beneficiary.document_downloaded`; the export records the count), the
retention tests already in `BeneficiariesTest` and `ComplianceTest` re-run
against records created through the screens, and the "public site never reads `beneficiaries`"
test.

## 7. Audit granularity (proposed)

- `beneficiary.viewed`: one row per user per case per session, with
  `tier` = the highest tier section opened. A second open in the same
  session updates nothing — the first row is the record. This keeps the
  hash-chained `audit_logs` readable while still answering "who looked at
  this child's file in March".
- Ghana Card reveal, document download, export: **every** time, no
  de-duplication.
- Every status change: through the activity log (already), and the
  `close()` projection recorded as `beneficiary.closed`.

## 8. Questions for the safeguarding lead and the trustees

1. **Is the data list in §4 the data the foundation actually collects?**
   Anything in the table that is not collected should be removed from the
   form (and the column dropped in Wave 2). Anything collected on paper that
   is not in the table must be added *with its classification* before the
   build. Candidates we suspect: disability, marital status, number of
   dependants, HIV status (which would be Tier C and, we would advise,
   not held at all unless a specific programme needs it).
2. **Who is the safeguarding lead, as a role?** The design assumes a
   **Safeguarding Lead** role (sees Tier C on every case, approves/declines,
   records consent) distinct from Programme Officer (works own cases). Is
   that one person, and is it the same person as the data-protection lead
   (who alone exports)? If they are the same person, one role; if not, two.
3. **New permissions to seed:** `beneficiaries.view_sensitive`,
   `beneficiaries.export` (split from `manage`), `beneficiaries.consent`.
   Agree, amend, or say the two existing permissions are enough.
4. **Should the Super Admin see Tier C at all?** The design says "see, not
   edit". The stricter answer is "no", with the Safeguarding Lead the only
   route. It is a trustee decision.
5. **Retention anchors.** A declined application is kept 24 months for
   appeals; a case record 72 months from close; a medical document 24
   months from close. Are these the periods in the safeguarding policy?
   They were set in Phase 11 from the Act's principles, not from a policy
   document, because none was supplied.
6. **Consent form.** The design requires a signed data-processing consent
   (or a guardian's) as an uploaded document before a case can be saved.
   Is there a form? If not, one is needed before the build, in English and
   in the local languages of the communities served, and this application
   can hold the template under Documents.
7. **Case notes about third parties.** A note about a child names parents,
   teachers, neighbours. Act 843 gives those people rights too. Guidance to
   staff on what a note may contain is a policy matter; the application can
   only enforce "append-only, attributed, dated".
8. **Photographs of children.** The consent machinery exists. The question
   is the *default*: the design defaults every consent to *not given* and
   never publishes without a row. Confirm.
9. **Where is the paper going?** Once cases are on screen, do the paper
   files get scanned in (as `identity`/`referral` documents, with their own
   retention) or stay in the cabinet? Both are defensible; "both, with no
   rule" is not.

## 9. Effort and order (for the Wave 2 plan)

1. Encryption at rest + blind index for the ID number — 2 days, with the
   test that a plain-text ID number cannot be found in a database dump.
2. Field map, generated form/infolist/table, policy tiers — 4 days.
3. Case page, actions, documents (signed download), consents — 5 days.
4. Case notes table and append-only UI — 2 days.
5. Access-matrix, audit, retention and "public never reads" tests — 3 days.
6. Export for the data-protection lead — 1 day.
7. Manual chapter, screenshots, a training session with the safeguarding
   lead, and the first real record entered together — 2 days.

Nineteen working days, four weeks. It starts when §8 has answers.
