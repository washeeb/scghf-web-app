# 9. What not to touch, and who to call

## Do not touch

| Thing | Why | If you think it needs changing |
|---|---|---|
| **Site settings → Donations → the payment keys** and anything with *Paystack* in the name | one wrong character and no gift can be taken | the developer, with the treasurer |
| **Site settings → Search engines → Allow indexing** | off means Google removes the site; on means it lists it | it is on for the live site and off for the practice site; leave it |
| **The General Fund appeal** | every gift without a chosen appeal goes there; the site expects it to exist | never unpublish or rename it |
| **System → Staff accounts → roles** | the wrong role shows somebody the donors' details | a Super Admin, and only for a named reason |
| **Email templates → the receipt** | the receipt is a financial document with a number; the placeholders must stay | change wording only; keep every `{{ … }}` |
| **Communications → Suppressions** ("do not contact") | people asked not to be contacted; the law says we honour it | release an entry only with the person's own written request, and the site asks you why |
| **System → Site Health** | it reports; it does not change anything — but it is telling you about real problems | read it weekly; act on red |
| Anything under **System → Failed jobs** | it is the developer's list | tell the developer if it is not empty |
| **Redirects & 404s** in bulk | a wrong redirect sends people in circles | one at a time, and test the old address afterwards |
| The **Door** page during an event | it checks people in; a misclick marks somebody arrived | only the steward on the door |

## Never

- Share your sign-in, or use somebody else's.
- Enter a card number anywhere in the admin. The site never asks for
  one; if it seems to, stop and call.
- Delete a photograph of a person to "remove" it — **withdraw** it
  (chapter 4), so the record of why stays.
- Type anything into the browser's address bar that somebody sent you in
  a message asking you to "check the admin". Open the panel yourself from
  your bookmark.

## When something looks wrong

1. **Site Health** (System → Site Health). Red rows have a sentence
   saying what to do.
2. If a donor says they paid and it is not on the list: chapter 5,
   *Statuses*; then the developer with the reference the donor has.
3. If the site is down: try it on a phone on mobile data first. If it is
   still down, call — see below.

## Who to call

The contacts are kept in the office, not in this manual, so they stay
current. The list needs these roles filled:

| Role | For |
|---|---|
| **The developer** | anything technical: the site is down, a deploy, an error message, a payment that is missing, a red row on Site Health you do not understand |
| **The treasurer / finance lead** | refunds, reconciliation, anything under Finance that looks wrong |
| **The safeguarding lead** | a photograph of a child, a consent question, a concern raised through the site |
| **The hosting company** (InMotion) | the account, billing, disk space — usually via the developer |
| **Paystack support** | only via the treasurer or the developer |

Write the names and numbers on the quick reference card (chapter 10)
and keep it by the desk.
