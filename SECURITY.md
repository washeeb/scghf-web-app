# Reporting a security issue

**Do not open a GitHub issue for a security problem.** Issues are visible
to everyone with access to the repository, and a vulnerability written
down in public is a vulnerability with a deadline.

Instead, email the foundation's general enquiries address (the one in
*Settings → Contact* on the site, and in the footer of every page) with
**"Security"** in the subject line, or message the developer directly.
Say what you found, where, and how to see it. A screenshot helps; a proof
of concept that touches real donor data does not — stop at the point
where you can show the problem exists.

You will get an acknowledgement within two working days and a fix or a
plan within ten. If the problem involves money, card data or personal
data, it is treated as an S1 (see `docs/PHASE-14-QA.md` §6.2) the moment
it is read.

## What counts

Anything that would let somebody: see or change another person's data;
move, count or refund money they should not; sign in as somebody else or
without a second factor; run code or queries on the server; bypass the
photograph-consent rules; or make the site unavailable to donors.

## What does not

Reports from automated scanners with no demonstrated impact; missing
"best practice" headers on the staging site (it is deliberately not
production); rate limits that exist on purpose; the presence of the fake
payment gateway outside production (that is what it is for).

## Scope

The production site, its admin panel, the staging site, and this
repository. Paystack, the email and SMS providers and the host are
outside our control; report problems with them to them, and tell us too.

## Thank you

The foundation cannot pay bounties. It can say thank you in the
`CHANGELOG.md`, by name or not, as you prefer.
