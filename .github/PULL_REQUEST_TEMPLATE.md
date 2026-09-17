## What and why

<!-- One paragraph. What changes, and what problem it solves. Link the phase or issue. -->

Phase: <!-- e.g. Phase 4 — Application Foundation -->
Closes: <!-- #123, or "n/a" -->

## How to verify

<!-- Exact steps a reviewer follows. URLs, credentials to use, what they should see. -->

1.
2.
3.

---

## Checklist

**Always**
- [ ] `vendor/bin/pint` and `vendor/bin/phpstan analyse` run; CI green (lint/test, coverage, browser)
- [ ] No secrets added; any new `.env` key is documented in `.env.example`
- [ ] `CHANGELOG.md` updated
- [ ] No hardcoded content in Blade — strings, phone numbers, emails, addresses,
      colours and images all come from the CMS/settings layer

**If this touches money, auth, or webhooks** *(required, not optional)*
- [ ] Pest tests added and passing; a bug fix carries the test that fails without it
- [ ] All amounts are integer pesewas — no floats anywhere in the money path
- [ ] Every outbound Paystack call includes `"currency": "GHS"`
- [ ] Payment state changes come only from the verified webhook, never the redirect
- [ ] Webhook handling is idempotent — replaying the same event changes nothing
- [ ] No card data touched, logged, or stored

**If this touches the UI**
- [ ] Checked in **both** light and dark theme, component by component
- [ ] WCAG 2.2 AA contrast verified for every new colour pair
- [ ] Keyboard navigable; visible focus ring; sensible tab order
- [ ] Works at 320px width; tap targets ≥ 44×44px
- [ ] Images have alt text; decorative ones are `aria-hidden`

**If this touches uploads or beneficiary data**
- [ ] EXIF/GPS stripped on upload
- [ ] Consent record required before a beneficiary image or story can be published
- [ ] No personal data written to logs

**If this touches the database**
- [ ] Migration is reversible (`down()` works)
- [ ] Migration is expand-only — no drop or rename in the same deploy as code that needs it
- [ ] Every foreign key and filtered column is indexed

**If this needs a manual server step**
- [ ] Documented in the PR body below, and flagged as `BREAKING CHANGE:` in the commit

## Manual steps required on deploy

<!-- New .env keys, a cron entry, a cPanel setting, a one-off artisan command.
     Write "none" if there are none. -->

none

## Shared-hosting check

- [ ] Introduces no dependency on Docker, root, Redis, Supervisor, or a persistent Node process
- [ ] Any new scheduled work runs inside the 55-second cron worker budget
- [ ] Any new media conversions considered against the inode budget

## Screenshots

<!-- Light and dark, mobile and desktop, for any visual change. -->
