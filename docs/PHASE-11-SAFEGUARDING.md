# Safeguarding: what has to happen before a volunteer works with children

## Why this document exists

Two of the foundation's four divisions exist to work with orphans and
widows. A volunteer who will be alone with a child is the single largest
safeguarding exposure the foundation carries, and the ordinary failure is
not malice — it is a keen applicant, a busy administrator, and "we'll do the
police check next week".

So the checks are not a policy people are asked to remember. They are in
`config/compliance.php` and **`VolunteerApplication::approve()` refuses**
while any is outstanding, naming what is missing. This document says what
the code enforces, what it deliberately does not decide, and what the
trustees still have to.

## The two kinds of role

Every volunteer opportunity carries a flag, *involves vulnerable contact*,
set by whoever creates it and **defaulting to yes**. An applicant to a
flagged role gets the full set of checks; an applicant to an unflagged one
— an event steward, a driver, somebody folding leaflets — gets the basic
set. Requiring a police clearance to hand out flyers would mean the
foundation either never recruits anybody or starts treating the requirement
as a formality, and a formality is not a safeguard.

A general application (no role named) is treated as vulnerable-contact.

## The checks (`compliance.safeguarding.required_checks`)

Each is a row in `safeguarding_checks` against the application, opened
automatically at submission, and closed only with an outcome, a reference,
a date, and the name of the staff member who recorded it.

| Check | What "passed" means | Evidence recorded |
|---|---|---|
| **Signed safeguarding declaration** | The applicant read the policy, disclosed any conviction, caution or investigation relevant to children or vulnerable adults, and agreed the declaration. | The declaration text as it stood, the timestamp, the IP. Captured by the form; the check is passed on submission. |
| **Ghana Police Service criminal record check** | A police clearance certificate obtained *for this applicant*. Applied for against their date of birth, which is why the form requires it for flagged roles. | Certificate number as the reference; expiry set to `clearance_valid_months` (24) from issue. |
| **First reference, taken up** | A referee actually contacted and spoken to — not a name and number supplied. | Who was spoken to, when, by whom. The applicant now supplies two referees on the form (name, relationship, phone/email) so this is doable. |
| **Second reference, taken up** | A second, *independent* referee — one who does not know the first. One referee can be a friend; two who do not know each other rarely both are. | As above. |
| **Face-to-face interview** | Conducted by someone other than the person who recruited them. | Date, interviewer. The application's *Arrange interview* / *Interview done* actions record the date; the check is closed separately with the interviewer's name. |

**Basic set** (`basic_checks`): the declaration only.

Each check can also be **waived** — with a written reason and the waiver's
name on it — or **failed**, which ends the application: `approve()` refuses
while any check is failed, and that decision is revisited explicitly, never
overridden from the approve button.

## After approval

- The volunteer record is created with `is_cleared = true` and the police
  clearance's expiry date. **`Volunteer::isCurrentlyCleared()` re-reads the
  checks every time it is asked**; a clearance that expires makes the
  volunteer un-rosterable (`isAvailable()` false, shift scheduling hidden)
  and the *Volunteers* list flags "clearance lapsing within 60 days".
- A **concern** raised about a volunteer **suspends them immediately**
  (`suspend_on_concern`), before any investigation. Not a punishment, not a
  finding — a precaution, and the order of events any safeguarding policy
  worth having insists on. Closing the concern needs a written outcome.
- Every view of a volunteer's or applicant's personal data is audited
  (`volunteer.pii_viewed`); `volunteers.view_pii` gates the contact and
  next-of-kin details separately from seeing that the person exists.
- Retention (`config/compliance.php`, Act 843): declined applications are
  destroyed 24 months after the decision; a volunteer's record is kept
  6 years after they leave, then de-identified. A disclosed conviction is
  classed as the most sensitive element on the file.

## What the code does NOT decide — trustee decisions

1. **Whether this is the right list.** The five checks are what a careful
   NGO in Ghana would ask; they are not a statement of what Ghanaian law
   requires or what the Department of Social Welfare expects for a
   registered organisation. The trustees should confirm the list with DSW
   and, if a check is added or removed, it is one entry in
   `config/compliance.php` and takes effect for every application still
   open.
2. **Who may sign off a check.** Today, anybody with `volunteers.manage`.
   If the trustees want a named safeguarding lead, that is a permission to
   seed (`volunteers.safeguard`) and a one-line change in
   `ChecksRelationManager`.
3. **The clearance validity period.** 24 months is a default. The Ghana
   Police Service certificate carries no expiry of its own.
4. **Two people on the interview.** The config asks that the interviewer be
   someone other than the recruiter; the code records one name. Whether a
   second person must be present is the trustees' call.
5. **Volunteers who are themselves under 18.** The form refuses applicants
   under 18 for flagged roles. Youth volunteering (a 16-year-old helping at
   a Saturday club) is not supported and would need its own consent flow.

## Checking it works

- Apply to a flagged role on the public site without two referees → the
  form refuses.
- In the admin, try *Approve* on a new application → refused, naming the
  four outstanding checks.
- Record the checks one by one on the *Safeguarding checks* tab; *Approve*
  succeeds only after the last.
- Set the police clearance's expiry to yesterday, open the volunteer →
  *Cleared* reads LAPSED, *Schedule a shift* is gone.
- *Raise a concern* → status Suspended at once; *Close the concern* needs
  an outcome.

`tests/Feature/VolunteersTest.php` and `tests/Feature/SafeguardingTest.php`
prove each of these on every run.
