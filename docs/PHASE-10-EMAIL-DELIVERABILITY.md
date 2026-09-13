# Email deliverability: why receipts land in spam, and the setup that stops it

## Why this document exists

A donation receipt that lands in spam is, to the donor, a receipt that never
came. They will not look for it. Some will assume the payment failed and give
again; some will assume the foundation is careless; a few will ask their bank
to reverse the charge. Nothing in the application can tell the difference —
the log says `sent`, the provider says `delivered`, and the mailbox filed it
under junk. Deliverability is the part of email that cannot be tested from
inside the code, which is why it gets its own document and its own row on
the Site Health page.

**Decision (Phase 10): transactional and marketing mail goes out through
Resend over its API, from a dedicated sending subdomain
(`mail.greaterhopefoundations.com`) with SPF, DKIM and DMARC published.**
cPanel's own SMTP is not used for anything a donor receives. The reasons
follow; the steps to set it up are at the end.

## Why mail from shared hosting lands in spam

The InMotion server is shared. Its outgoing IP (`23.235.219.254`, per the
Phase 1 blueprint) is the same IP every other tenant on that box sends from.
Mailbox providers — Gmail, Outlook, Yahoo, and every Ghanaian corporate
mailbox behind Microsoft 365 — score the **IP's** reputation, not the
sender's. So:

- If another tenant runs a compromised WordPress that sends 40,000 phishing
  messages overnight, the foundation's receipts go to spam the next morning,
  and stay there for weeks. Nothing the foundation did, nothing it can undo.
- Shared hosts throttle outbound mail (typically 100–500/hour, sometimes
  per-domain, sometimes per-account) and reject the excess without telling
  the application in any way it can act on. A newsletter to 600 subscribers
  from cPanel SMTP is 400 messages that silently did not go.
- Many shared hosts route through a relay (InMotion uses MailChannels on
  some plans), which adds its own reputation and its own limits.
- cPanel SMTP has no bounce webhook. A hard bounce comes back as a message
  to the sending mailbox that nobody reads. The suppression list built in
  Phase 3 would stay empty; the same dead addresses would be hit on every
  send; the bounce rate would climb; and rising bounce rates are themselves
  a spam signal. Receipts would be the first thing to stop arriving.

A transactional provider gives the foundation its own reputation (per
domain, and on paid tiers per IP), authenticated sending, real bounce and
complaint webhooks — which the application already consumes at
`/webhooks/delivery/{provider}` — and a dashboard that shows what actually
happened to each message.

## The provider comparison

Judged for a Ghanaian non-profit sending perhaps 300 receipts and one
newsletter a month, paying in foreign currency on a card, with nobody on
staff who reads a mail-server log.

| | **Resend** | Postmark | Brevo (ex-Sendinblue) | Mailgun | cPanel SMTP |
|---|---|---|---|---|---|
| Free tier | 3,000/month, 100/day, 1 domain | 100/month | 300/day | none (trial only) | included |
| First paid tier | $20/mo — 50,000 | $15/mo — 10,000 | ~$9/mo — 5,000 | $15/mo — 10,000 | — |
| Laravel transport | native (`resend`) + `resend/resend-php` — installed | native, needs `symfony/postmark-mailer` | SMTP relay only | needs `symfony/mailgun-mailer` | native `smtp` |
| Bounce/complaint webhooks | yes (Svix-signed) — **built and tested** | yes (HTTP Basic auth, no signature) — endpoint exists, verifier not written | yes (unsigned; IP allowlist) — not built | yes (signature inside the JSON body) — endpoint exists, verifier not written | none |
| Marketing mail allowed | yes | transactional focus; marketing stream separate | yes, it is their business | yes | discouraged |
| Deliverability reputation | good; newer | excellent; the benchmark | good on paid, weaker on free | good | shared IP — poor |
| Signup friction for a Ghanaian entity | card, no phone verification | card; manual approval, asks for business details | card or PayPal | card; approval | none |
| Dedicated IP | paid add-on | paid add-on | paid add-on | paid add-on | never |

**Why Resend.** The free tier covers the foundation's whole expected volume
with room to grow; the Laravel transport is first-party and installed; its
webhooks are the only ones of the four that are properly signed
(Svix: `{id}.{timestamp}.{body}`, replay-protected by the timestamp), and
that verifier is now written and tested; and it will carry both receipts
and the newsletter from one domain, which keeps the setup to one set of DNS
records. Postmark is the deliverability gold standard but its free tier is
100 messages — smaller than a single month's receipts — and its marketing
policy would mean a second provider for the newsletter. Brevo's free tier
is generous but its webhooks are unsigned and it would run through the
generic `smtp` mailer with no delivery reporting into the application.

**If Resend ever becomes unavailable**, the fallback is Postmark: install
`symfony/postmark-mailer`, set `MAIL_MAILER=postmark` and `POSTMARK_API_KEY`.
Its webhook endpoint exists, but Postmark authenticates by HTTP Basic
credentials in the endpoint URL rather than a signature header, so
`DeliveryWebhookController::verify()` needs a `basic` scheme before its
bounces would be acted on — recorded as an open question, not built,
because Postmark is not the provider in use. The same is true of Mailgun,
which puts its signature inside the JSON body. Any provider's SMTP relay works
immediately through `MAIL_MAILER=smtp` with the relay credentials in
`MAIL_HOST` / `MAIL_USERNAME` / `MAIL_PASSWORD` — at the cost of no bounce
feedback into the suppression list.

## Use a sending subdomain

Send from `noreply@mail.greaterhopefoundations.com`, not from
`@greaterhopefoundations.com`.

- The root domain's reputation stays with the people who write from it —
  `info@`, the director's mailbox. If the newsletter ever gets a burst of
  complaints, the director's ordinary correspondence is unaffected.
- The subdomain's DNS records (below) are separate from whatever cPanel has
  already published for the root domain, so nothing existing breaks.
- The Reply-To stays `info@greaterhopefoundations.com` (the
  `contact.email_general` setting), so a donor who replies reaches a person.

The subdomain needs no mailbox and no hosting. It is a set of DNS records.

## SPF, DKIM and DMARC

Three DNS records at the registrar or cPanel *Zone Editor*. Resend prints
the exact values under *Domains → Add domain*; the shapes are:

**SPF** — which servers may send for the subdomain.
```
mail.greaterhopefoundations.com.   TXT   "v=spf1 include:amazonses.com ~all"
```
(Resend sends via SES; the include is what Resend's dashboard shows, use
that value, not this one.) One SPF record per name — if one already exists,
merge the `include:` into it rather than adding a second, which makes both
invalid.

**DKIM** — a signature on each message proving it was not altered.
```
resend._domainkey.mail.greaterhopefoundations.com.   TXT   "p=MIGfMA0GCSq..."
```
Resend gives the selector and the key. Some registrars need the long value
split into 255-character quoted chunks; cPanel's Zone Editor does this
itself.

**DMARC** — what a receiving mailbox should do when SPF/DKIM fail, and
where to send reports.
```
_dmarc.mail.greaterhopefoundations.com.   TXT   "v=DMARC1; p=quarantine; rua=mailto:dmarc@greaterhopefoundations.com; pct=100; adkim=s; aspf=s"
```
Start at `p=none` for the first two weeks, read the aggregate reports (any
free DMARC report viewer will parse them), then move to `p=quarantine` and,
once nothing legitimate is failing, `p=reject`. The root domain should have
a DMARC record too, at `_dmarc.greaterhopefoundations.com`, even if only
`p=none` — Gmail and Yahoo have required it since February 2024 for anybody
sending them more than a trickle.

**Also required by Gmail/Yahoo since 2024, and already in the code:** a
one-click `List-Unsubscribe` header on every marketing message
(`App\Mail\RenderedMessage`), a working unsubscribe link in the body, a
complaint rate under 0.3 % — which is what the suppression list and the
double opt-in protect.

## Warm-up

A new domain that sends 600 messages in its first hour looks like a
spammer's new domain. Resend's free tier caps at 100/day, which enforces a
warm-up by itself. If the paid tier is bought before the first newsletter:

| Week | Newsletter batch | Notes |
|---|---|---|
| 1 | receipts only | let transactional mail establish the domain |
| 2 | 100/day | `MAIL_BULK_PER_HOUR=50` |
| 3 | 300/day | `MAIL_BULK_PER_HOUR=100` |
| 4+ | full list | `MAIL_BULK_PER_HOUR=200`, the default |

`MAIL_BULK_PER_MINUTE` / `MAIL_BULK_PER_HOUR` are the throttle the campaign
sender obeys; the outbox holds what does not fit and sends it on the next
cron pass. The heading of *Communications → Outbox* shows the allowance in
force.

## Bounces and complaints

Already built (Phase 3, extended Phase 10). The flow:

1. Resend POSTs to `https://<site>/webhooks/delivery/resend`. Set that URL
   under *Webhooks → Add endpoint*, tick `email.delivered`, `email.bounced`,
   `email.complained`, `email.delivery_delayed`, `email.failed`, and copy
   the signing secret (`whsec_…`) into `RESEND_WEBHOOK_SECRET`.
2. The controller verifies the Svix signature and stores the raw event.
   An unsigned or stale event is stored and **never acted on** — without
   that, anybody who guessed a donor's address could stop their receipts.
3. On the queue, a **permanent bounce** suppresses the address for every
   category (receipts included — the address does not exist), a
   **complaint** suppresses everything, a **transient bounce** or delay is
   noted on the log row and suppresses nothing, `delivered` marks the row.
4. The suppression appears under *Communications → Suppressions*, with the
   provider's own words as the reason. `suppressions.release` can lift one
   with a reason, which is audited.

Check it after setup: send a test from *Communications → Email templates →
Send a test* to `bounced@resend.dev` (Resend's permanent test address) and
watch the row turn `bounced` and the suppression appear within a minute of
the queue cron.

## Setting it up, in order

1. **Resend account** — sign up, add a card if the paid tier is wanted,
   *API Keys → Create* with *Sending access* only, restricted to the domain.
2. **Domain** — *Domains → Add* `mail.greaterhopefoundations.com`, region
   closest to the readers (eu-west for Ghana, ~100 ms better than us-east).
   Publish the three records it prints. Wait for *Verified* (minutes to an
   hour).
3. **Webhook** — as above; secret into `.env`.
4. **`.env` on the server**
   ```
   MAIL_MAILER=resend
   RESEND_API_KEY=re_…
   RESEND_WEBHOOK_SECRET=whsec_…
   MAIL_FROM_ADDRESS="noreply@mail.greaterhopefoundations.com"
   MAIL_REPLY_TO_ADDRESS="info@greaterhopefoundations.com"
   ```
   then `php artisan config:cache`.
5. **Site Health** — the *Email sending* row should read **Resend**. It
   reads *Resend chosen, no key* if step 4 was missed and *cPanel SMTP*
   with a warning if `MAIL_MAILER=smtp` still points at the shared box.
6. **Prove it** — *Communications → Email templates → donation.receipt →
   Send a test* to a Gmail address and an Outlook address. Both should
   arrive in the inbox, and in Gmail *Show original* should read
   `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`. Then the bounce test above.
7. **Root-domain DMARC** — publish `_dmarc.greaterhopefoundations.com`
   `v=DMARC1; p=none; rua=mailto:dmarc@greaterhopefoundations.com` so the
   root domain meets the 2024 requirement too; tighten later.

## What to watch, monthly

- Resend dashboard: bounce rate under 2 %, complaint rate under 0.1 %.
  Above that, stop the newsletter and look at the list before sending again.
- DMARC aggregate reports: any source other than Resend sending as the
  subdomain is somebody else, and the policy should be at `reject`.
- *Communications → Suppressions*: a sudden run of hard bounces after an
  import means the import was bad, not the mail.
- *Site Health → Email sending* stays green, and *Failed jobs* stays empty.
