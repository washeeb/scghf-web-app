# Phase 8 — Payments test plan

How to prove, before a single real cedi moves, that money cannot be lost,
double-counted, or credited without proof. Three environments, in order:

| Environment | `PAYMENT_DRIVER` | Keys | What it proves |
|---|---|---|---|
| Local / CI | `fake` | none | every state the code can reach, deterministically |
| Staging | `paystack` | `sk_test_…` | the real gateway, the real webhook, no money |
| Production | `paystack` | `sk_live_…` | one small live gift, refunded the same day |

The admin panel says which one it is on every page: a **No gateway** band for
`fake`, an amber **Test mode** band for test keys, nothing at all for live.
If the band is missing on staging, stop — the site is holding live keys.

---

## 1. Local and CI — the fake gateway

`PAYMENT_DRIVER=fake` (the default). Nothing leaves the machine. The fake
gateway is deterministic; the rules are in `app/Payments/FakeGateway.php`.

### 1.1 Card / redirect flow

1. `/donate` → amount, name, email → **Give**.
2. You land on `/payments/fake/{reference}`, the sandbox checkout. It offers
   **Pay**, **Fail** and **Abandon**.
3. **Pay** delivers a signed webhook and returns you to `/donate/callback`,
   which verifies with the gateway before showing the thank-you page.

| Reference suffix | `verify()` answers |
|---|---|
| *(none)* | success |
| `…-FAIL` | failed |
| `…-PENDING` | still pending |
| `…-SHORT` | success, but for less than expected → **needs review**, never completed |

### 1.1a The popup checkout

Settings → Donations → **Card checkout** → "A window over our page". The
form now lands on `/donate/{ulid}/pay` instead of redirecting. With the
fake driver the page shows the summary and one button to the sandbox (no
Paystack script is loaded); on staging it loads `js.paystack.co/v2/inline.js`
and resumes the transaction from the access code. Check:

- closing the window shows "nothing has been charged" and a button that
  reopens it; the gift stays `pending`
- success lands on `/donate/callback?reference=…`, which verifies with the
  gateway before the thank-you page
- reloading `/pay` never starts a second transaction (it is a GET that only
  resumes); once the gift is settled it redirects to the thank-you
- with JavaScript off the button goes to Paystack's own page

### 1.1b Turnstile

Set `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` to Cloudflare's always-pass
test pair (in `.env.example`) and the widget appears above **Continue to
payment**. Remove either key and it disappears with no other change. With
the always-**fail** pair (`2x…`) the form is refused with a message and
nothing is charged.

### 1.2 Direct Mobile Money

On the form choose **Prompt to my phone**, a network, and a number:

| Wallet number ends in | What the fake gateway does |
|---|---|
| `99` | declined at once — the donor is told nothing was taken |
| `00` | `send_otp` — the page asks for the code Telecel would text |
| anything else | `pay_offline` — "approve the prompt on your phone" |

On the OTP page, `000000` is refused; any other six digits moves to
`pay_offline`. The waiting page polls `/donate/{donation}/status` every few
seconds; with JavaScript off, **Check again** reloads and verifies. In a
non-production environment the waiting page links to the sandbox so the
prompt can be "approved" from the browser.

### 1.3 What to assert after every path

| Path | `donations.status` | `payment_transactions.status` | Receipt | Cause total | Donor totals |
|---|---|---|---|---|---|
| Pay | `completed` | `success` | issued, emailed, PDF downloadable | +amount | +1 gift |
| Fail | `failed` | `failed` | none; `donation.failed` email queued | unchanged | unchanged |
| Abandon | `abandoned` | `abandoned` | none; `donation.abandoned` only if `donations.abandoned_followup` is on **and** the donor consented | unchanged | unchanged |
| `-SHORT` | `pending` | `needs_review` | none | unchanged | unchanged |

Check the last two columns in **Finance → Donations → view** and on the
donor record. A number that moved for a failed gift is a bug, not a display
issue.

### 1.4 The automated suite

```bash
php artisan test tests/Feature/GivingFlowTest.php tests/Feature/FinanceAdminTest.php tests/Feature/PaymentsTest.php tests/Feature/ReconciliationTest.php tests/Feature/RecurringGivingTest.php tests/Feature/ReceiptsTest.php tests/Feature/DonatePageTest.php
```

Everything in section 1 is covered there; the manual walk is for the eyes,
not the ledger.

---

## 2. Staging — Paystack test keys

`PAYMENT_DRIVER=paystack`, `PAYSTACK_SECRET_KEY=sk_test_…`,
`PAYSTACK_PUBLIC_KEY=pk_test_…`. Staging is noindexed and has its own
database. In the Paystack dashboard, switch to **Test mode** and set the
webhook URL to `https://staging.…/webhooks/paystack`.

### 2.1 Test cards

Paystack's published test cards (in test mode only; they are refused by live
keys):

| Card | Number | Extra | Outcome |
|---|---|---|---|
| Success, no authentication | `4084 0840 8408 4081` | CVV `408`, any future expiry | success |
| Success with PIN + OTP | `5060 6666 6666 6666 666` | PIN `1234`, OTP `123456` | success |
| Declined | `4084 0800 0000 5408` | any | insufficient funds |

Anything else current is at <https://paystack.com/docs/payments/test-payments>.
Do not type a real card number into staging, ever; the point of test keys is
that nobody has to.

### 2.2 Test mobile money

Paystack provides test wallet numbers per network for Ghana on the same
page; use those. What to check is the same as 1.3, plus:

- the `momo_provider` on the gift matches what was chosen
- the `channel` on the transaction reads `mobile_money`
- the authorisation is **not** reusable (MoMo authorisations are not), so a
  monthly gift started by MoMo is expected to fail its second charge and
  send `recurring.failed` — this is correct behaviour, and the reason the
  form says a regular gift is best set up by card

### 2.3 The webhook, three ways

**Normal delivery.** Pay with the success card. Within a few seconds
**Finance → Webhook events** shows a `charge.success` row, *signature valid*,
*processed*. The gift completes even if the donor closes the browser before
the callback.

**Duplicate delivery.** In the Paystack dashboard → Webhooks, resend the same
event. The row count in Webhook events does **not** increase (same
`event_id`), and the gift is not counted twice — the cause total, the donor's
gift count and the receipts table all still say one.

**Out of order.** Start a gift, then in the dashboard resend an *older*
`charge.success` for a different reference before the new one arrives.
Each event settles only its own transaction; neither touches the other.

**Replay from our side.** Any stored event with a valid signature has a
**Replay** button (permission `payments.replay_webhook`). Replaying a
processed event is a no-op on the ledger and is audited. An event whose
signature failed has no button.

### 2.4 Forging a webhook (must be refused)

```bash
curl -i -X POST https://staging.…/webhooks/paystack \
  -H 'Content-Type: application/json' \
  -H 'x-paystack-signature: not-a-real-signature' \
  -d '{"event":"charge.success","data":{"reference":"SCGHF-D-XXXX","status":"success","amount":5000,"currency":"GHS"}}'
```

Expected: HTTP 200 (we never tell a sender whether it got the signature
right), a row in Webhook events marked *SIGNATURE INVALID*, and **no change**
to the donation. If `PAYSTACK_WEBHOOK_IPS` is set, a correctly signed
delivery from any other address is treated the same way.

### 2.5 Failure simulation on staging

| To simulate | Do this |
|---|---|
| A declined card | the declined card above |
| A donor who abandons | close the Paystack page; run `php artisan scghf:reconcile-payments --execute` after the abandonment window; the gift becomes `abandoned` |
| A late settlement | pay, but take the webhook URL off the Paystack dashboard first; the gift stays `pending`; put the URL back and run reconciliation — the gift is recovered and receipted once |
| An amount mismatch | cannot be produced through Paystack; covered by the `-SHORT` fixture locally and `ReconciliationTest` |
| A failed recurring charge | locally: an authorisation code containing `DECLINE` is refused by the fake gateway (set it on the subscription row, then `php artisan scghf:charge-recurring --execute`). On staging: start a monthly gift by test MoMo — its authorisation is not reusable — and run the same command on the due date; `recurring.failed` goes out, and after `RECURRING_MAX_FAILURES` the gift pauses with `recurring.paused` |
| A refund | Finance → Donation → **Request a refund**, then a *different* user → Finance → Refunds → **Approve and send**. Paystack test mode answers `refund.processed` by webhook within a minute; the gift becomes `refunded` and the cause total drops |

### 2.6 Reconciliation

`php artisan scghf:reconcile-payments` (dry run) and `--execute`, or the
**Run reconciliation now** button on Finance → Reports. Compare the "Settled,
not yet reconciled" figure with the Paystack test-mode transactions list for
the same window, then mark each matched gift **reconciled** on its page.
"Needs review" must be zero at the end of the exercise.

---

## 3. Production — one live gift

Only after 1 and 2 pass, and only with:

- `APP_ENV=production`, `APP_DEBUG=false`
- `sk_live_…` in `.env` on the server only — Site Health must read **Live keys**
- the live webhook URL set in the Paystack dashboard's **Live mode**
- the Paystack account and the bank account confirmed to belong to the foundation

Then: a GH₵ 1.00 gift by card from a trustee's own card, watch the webhook
row arrive, download the receipt, and refund it the same day through the
two-person flow. The refund should appear in the Paystack dashboard and the
gift should read `refunded` here. That is the whole live test; it costs the
gateway fee on one cedi.

---

## 4. Sign-off checklist

- [ ] Every row of table 1.3 observed locally
- [ ] Success, PIN/OTP and declined cards on staging
- [ ] Test MoMo wallet on staging, `pay_offline` then settled
- [ ] Duplicate webhook: counted once
- [ ] Forged webhook: stored, refused, ledger untouched
- [ ] Replay from admin: no double count, audited
- [ ] Refund with two people; self-approval refused
- [ ] Reconciliation run; "needs review" is zero
- [ ] Test-mode band visible on staging; **absent** on production
- [ ] One live cedi in, receipted, refunded
