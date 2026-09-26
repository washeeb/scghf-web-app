# PCI DSS: the foundation's SAQ-A posture

## The one-sentence version

**The application never sees a card number.** Every card, mobile-money and
bank payment is entered on Paystack's pages or inside Paystack's popup
(an iframe on Paystack's origin), and the only things that come back are a
reference, a status, an amount, an `authorization_code` token, the last
four digits and the card brand. That is the arrangement PCI DSS calls
**SAQ-A**: a merchant that has fully outsourced cardholder data capture to
a PCI-compliant third party (Paystack is Level 1 certified).

SAQ-A is the shortest questionnaire and the lightest ongoing obligation.
Keeping it means never doing the things in the last section.

## What we hold, and where

| Data | Held? | Where | Why |
|---|---|---|---|
| Full card number (PAN) | **Never** | — | Never reaches our servers. The form field is Paystack's. |
| CVV / expiry | **Never** | — | As above. |
| Cardholder name | Not from the card | `donations.donor_name` | The name the donor typed on *our* form, for the receipt. |
| Last four digits | Yes | `payment_transactions.card_last4` | Shown on the receipt and the donor's giving history ("MTN •••• 4321"). `PaymentTransaction::applyAuthorization()` refuses to store anything longer than four characters. |
| Card brand / network | Yes | `payment_transactions.card_brand` | "Visa", "MTN Mobile Money". |
| `authorization_code` | Yes | `payment_methods` / `payment_transactions` | Paystack's token for charging a saved method again (regular giving). Useless without Paystack's secret key. |
| Paystack secret key | Server `.env` only | `PAYSTACK_SECRET_KEY` | Never in the repository (`.env` is git-ignored and the deploy refuses to proceed if it is tracked). |
| Raw webhook bodies | Yes | `payment_webhook_events.raw_payload` | Paystack's event JSON. Contains `authorization` (token, last4, brand, bank) — **no PAN**, because Paystack never sends one. |

**Logs and audits.** `AuditLogger::scrub()` redacts any context key that
looks like a secret before it is stored. Application logs never receive
request bodies from the donate form; the Paystack signature, not the
payload, is what the webhook log line names.

## The two flows

**Redirect (default).** `PaystackService::initialise()` creates the
transaction server-side and the browser is sent to
`https://checkout.paystack.com/…`. The donor types the card number there.
Paystack redirects back to `/donate/callback` with a reference; the
application **ignores the redirect as proof** and waits for the signed
webhook (Phase 8: "payment truth comes from the webhook").

**Popup (a setting, Phase 8).** `js.paystack.co/v2/inline.js` draws
Paystack's iframe over our page. The card fields are inside the iframe on
Paystack's origin; our page cannot read them. The Content Security Policy
(Phase 12) allows exactly that script and that frame and nothing else
from outside.

Both flows are SAQ-A. The popup would become **SAQ-A-EP** only if our own
JavaScript touched the payment fields or we hosted the form; it does not
and we do not.

## What keeps us at SAQ-A — the controls that exist

1. **No card fields in any Blade template.** `grep -rn "card_number\|cvv"`
   finds nothing. A code review comment in `PaymentTransaction` says why.
2. **`card_last4` cannot hold more than four characters** — enforced in
   code, not policy.
3. **Webhook verification cannot be bypassed:** HMAC-SHA512 over the raw
   body with `hash_equals()`, the Paystack IP allowlist when configured, a
   unique `event_id` so a replay is a no-op, and processing on the queue
   after a `200`. `tests/Feature/PaymentsTest.php` proves each.
4. **Amount and currency re-verified** against what we expected; a
   mismatch holds the payment for review and (Phase 12) emails the alerts
   address at once.
5. **HTTPS everywhere** (`FORCE_HTTPS`, HSTS once enabled), secure,
   http-only, SameSite cookies, 2FA on every staff account.
6. **Anomaly alerts** (`scghf:payment-anomalies`): runs of failed payments
   (card testing) and of refunds reach a person within the hour.
7. **Secrets never in the repository**; rotation procedure in
   `docs/PHASE-12-SECURITY.md`.

## The annual paperwork

- Complete **SAQ-A** and its Attestation of Compliance once a year. The
  acquiring bank (whoever settles Paystack's payouts to the foundation's
  account) may ask for it; Paystack's own compliance page names the
  questionnaire. Most of the answers are "not applicable — fully
  outsourced"; the ones that are not are the controls above.
- Quarterly ASV scans are **not** required for SAQ-A.
- Keep Paystack's current Attestation of Compliance on file (downloadable
  from their dashboard or on request).

## Things that would break SAQ-A — never do these

- Add a card-number field to any form "to make it quicker".
- Log a request body from the donate or checkout pages.
- Store anything from a Paystack response beyond what `applyAuthorization()`
  keeps.
- Proxy or rewrite Paystack's checkout page or inline script through our
  server.
- Turn off webhook signature verification "temporarily".
- Email or SMS the last four digits together with the amount, date and
  name of the donor in one message to anybody but the donor.

If one of these is ever proposed, the answer is the questionnaire it would
move the foundation onto (SAQ-A-EP or SAQ-D), which is months of work and
an annual external assessment.
