# Payments — how money moves, end to end

The one document to read before touching `app/Payments`. The rules are in
`CLAUDE.md` (money is integer pesewas; truth comes from the webhook); the
test plan is `PHASE-8-PAYMENTS-TEST-PLAN.md`; the card-data posture is
`PHASE-12-PCI-DSS-SAQ-A.md`.

## 1. The shape

```
 donor's browser                 this application                        Paystack
 ───────────────                 ────────────────                        ────────
 POST /donate ──────────────▶ DonationService::create()
                              PaymentManager::charge() ─── initialise ──▶ /transaction/initialize
                              ◀── authorization_url ────────────────────
 302 to Paystack ◀──────────
 pays on Paystack ─────────────────────────────────────────────────────▶
                              PaystackWebhookController ◀── charge.success (signed) ──
                              recordWebhook(): store raw, verify HMAC, 200 at once
                              ProcessPaymentWebhook (queue) → processWebhook()
                                → PaymentTransaction::settle() → Donation completed
                                → receipt issued, thank-you email/SMS queued
 302 back to /donate/callback ◀─────────────────────────────────────────
                              verifyAndSettle(): GET /transaction/verify — the same
                              settle(), idempotent, so the thank-you page is right
                              even if the webhook is still in the queue
 GET /donate/{ulid}/thank-you  shows what the DATABASE says, never the redirect
```

One `PaymentTransaction` per attempt, for donations and shop orders alike
(`payable_type`/`payable_id`). One webhook endpoint, one handler, one
`PaystackService` — nothing else calls Paystack.

## 2. The classes

| Class | Job |
|---|---|
| `App\Payments\PaystackService` | the only HTTP client to Paystack: `initialise`, `verify`, `chargeAuthorization`, `chargeMobileMoney`, `submitOtp`, `refund`, `verifySignature`. Every request carries `currency: GHS` and the amount in pesewas. Tested on the wire in `PaystackClientTest` |
| `App\Payments\FakeGateway` | the same contract without a network: deterministic outcomes by reference suffix (`-FAIL`, `-SHORT`), a sandbox page at `/payments/fake/{reference}` that signs and delivers a real webhook. `PAYMENT_DRIVER=fake`; the route does not exist in production |
| `App\Payments\PaymentManager` | the boundary: `charge()`, `chargeMobileMoney()`, `submitOtp()`, `chargeStored()` (recurring), `verifyAndSettle()`, `recordWebhook()`, `processWebhook()`, `applyResult()` |
| `App\Payments\DonationService` | builds a donation from the form: amount, fee cover, designations, donor resolution, attribution |
| `App\Payments\OfflineDonationService` | cash, cheque, bank transfer recorded by staff — same ledger, `gateway = offline` |
| `App\Payments\RecurringGivingService` | charges due subscriptions with the stored authorisation; dunning; pause after `RECURRING_MAX_FAILURES` |
| `App\Payments\RefundService` | request by one person, approve by another, send, and apply `refund.processed`/`refund.failed` |
| `App\Payments\ReconciliationService` | the sweep: settled-but-unrecorded, abandoned, mismatched; `scghf:reconcile-payments` |
| `App\Payments\ReceiptIssuer` / `ReceiptPdf` | numbered receipts (`receipt_sequences`), the PDF behind a signed link |
| `App\Payments\PayloadScrubber` | strips card-shaped keys from anything stored or shown |
| `App\Payments\AnomalyAlerts` | emails when failures per hour or refunds per day cross the `.env` lines |
| `App\Jobs\ProcessPaymentWebhook` | the queued processor: 5 tries, backoff 10/30/120/300 s, timeout 45 s |

## 3. The webhook, step by step

`POST {PAYSTACK_WEBHOOK_PATH}` (default `/webhooks/paystack`; CSRF-exempt
because it lives under `/webhooks/`), handled by `PaystackWebhookController`
→ `PaymentManager::recordWebhook()`:

1. **Read the raw body.** Not `$request->all()` — the signature is over the
   bytes, and `json_encode(json_decode($b))` is not `$b`.
2. **Compute** `hash_hmac('sha512', $rawBody, PAYSTACK_WEBHOOK_SECRET)`.
3. **Compare** with `x-paystack-signature` using `hash_equals()`. An empty
   or unset secret rejects everything (and logs critical) — an
   unconfigured webhook must never accept.
4. **Source IP**: if `PAYSTACK_WEBHOOK_IPS` is set, a correctly signed
   delivery from any other address is stored as invalid.
5. **Store** the event in `payment_webhook_events` — raw body, signature,
   `signature_valid`, source IP, `received_at` — **before** parsing. A
   malformed body is still evidence. `event_id` is unique at the database:
   a replay is refused by the engine, not by code that has to be right.
6. **Respond 200 immediately**, valid or not. Paystack retries anything
   else aggressively; and an attacker learns nothing from a 200.
7. **Queue** `ProcessPaymentWebhook` for a valid, unprocessed event. With
   the cron worker it runs within the minute.

`processWebhook()` then routes by `event_type`:

| Event | What happens |
|---|---|
| `charge.success` | `applyResult()` with the transaction object → `settle()` (§4) |
| `charge.failed` | `applyResult()` with a failed result → transaction and donation/order marked failed, donor told |
| `refund.processed`, `refund.failed` | `RefundService::applyWebhook()`: the refund row moves, the ledger moves only on `processed`, stock returns for an order |
| `transfer.success`, `transfer.failed`, `transfer.reversed` | stored and acknowledged; payouts are recorded by hand (`payouts`), not automated |
| `subscription.create`, `subscription.disable`, `subscription.not_renew`, `invoice.create`, `invoice.update`, `invoice.payment_failed` | stored and acknowledged. Recurring giving is driven by **our** scheduler charging stored authorisations (`scghf:charge-recurring`), not by Paystack subscriptions, so these are evidence rather than instructions |
| anything else | stored, acknowledged, not processed — an unrecognised event is a signal, not an error |

An event whose `gateway_reference` matches no transaction is logged and
marked processed. Replay from the admin (*Finance → Webhook events →
Reprocess*, permission `payments.replay_webhook`) runs the same path; a
processed event is a no-op on the ledger and is audited. Replay is hidden
for events whose body has been archived (`payload_archived_at`).

## 4. The state machines

### 4.1 `payment_transactions.status`

```
 initialised ──▶ pending ──▶ success
      │            │            ▲
      │            ├──▶ failed  │  (verify / charge.success)
      │            ├──▶ mismatch
      └──▶ abandoned (reconciliation, after PAYMENT_ABANDON_AFTER_MINUTES)
```

`settle()` is where the money rule lives, and it is the same code for the
webhook and the callback:

- a **final** status (success, failed, mismatch, abandoned) is never
  reopened — a late `charge.success` after a failure, or a second delivery
  after success, returns the existing status and changes nothing
- **currency** must equal the transaction's; **amount paid** must equal
  the amount expected, to the pesewa. Either mismatch → status
  `mismatch`, the donation goes to `needs_review`, `Log::critical`, and
  the anomaly email — **never** auto-completed. The `-SHORT` fixture in the
  fake gateway settles one pesewa light to prove it
- on success: `amount_paid_minor`, `currency_paid`, `paid_at`, the
  gateway's own `fees` (not our estimate), the allow-listed authorisation
  (`authorization_code`, `last4`, brand, bank, expiry — never a PAN)

### 4.2 `donations.status`

```
 pending ──▶ completed ──▶ refunded
    │             ▲
    ├──▶ failed   │ (a settled transaction; append-only from here: amounts never change)
    ├──▶ abandoned
    └──▶ needs_review  (mismatch; a person decides, then refund or a manual completion with a note)
```

`Donation::complete()` is idempotent: a completed gift stays completed
once; the cause total (`causes.raised_minor`) is incremented in SQL, once;
the receipt is issued once (`donation_receipts` is unique on donation). A
donation is never edited after completion — a wrong gift is refunded.

### 4.3 `orders.status`

`pending → paid → processing → packed → shipped → out_for_delivery →
delivered | collected → completed`, with `cancelled`, `refunded`,
`needs_review` from the sides. Stock is held at checkout and released by
`scghf:sweep-shop` if the payment never comes; returned on a confirmed
refund.

## 5. Recurring gifts

A monthly gift by card stores the `authorization_code` from the first
`charge.success` (`reusable: true`). `scghf:charge-recurring --execute`
(daily) charges what is due via `chargeAuthorization`, each charge a new
`payment_transaction` and, on success, a new `donation` under the same
subscription. Failures: `recurring.failed` to the donor with a signed
management link; after `RECURRING_MAX_FAILURES` the subscription pauses and
`recurring.paused` goes out. Mobile-money authorisations are **not**
reusable — the form says so, and the first failed charge is expected.

## 6. Debugging "the money was taken but nothing was recorded"

Work down the list; the first row that says *no* is the problem.

| # | Check | Where | If no |
|---|---|---|---|
| 1 | Is there a transaction for the reference? The donor's thank-you page and the Paystack dashboard both show `SCGHF-D-…` | *Finance → Donations*, search the reference; or `payment_transactions.gateway_reference` | The form never reached `DonationService::create()`: look for a 422/500 in the logs at that minute. The money was taken on Paystack against a reference we never issued — that is a Paystack-side charge, refund it there |
| 2 | Did the webhook arrive? | *Finance → Webhook events*, filter by the reference | Paystack dashboard → Webhooks → check the URL is the live one and the delivery log. Resend it from there. If none arrived at all: the webhook URL is wrong, or `.htaccess`/Cloudflare is blocking POSTs to `/webhooks/` |
| 3 | Is it *signature valid*? | same row | `PAYSTACK_WEBHOOK_SECRET` on the server is not the secret key of the account that took the payment (live vs test keys, or a rotated key). Fix `.env`, `config:cache`, then **Reprocess** the event |
| 4 | Is it *processed*? | same row, `processed_at` | The queue is not running: *Site Health → Background queue* stale, or the cron worker line is missing (`OPERATIONS.md`). `php artisan queue:work --once` on the server processes it now; then fix cron. If `failed_jobs` has it, *System → Failed jobs → Retry* |
| 5 | Is the transaction `mismatch`? | the transaction's status; the donation is `needs_review` | The amount or currency Paystack reported differs from what we asked. Read the raw payload on the event. If Paystack is right (the donor changed the amount on their side — possible with some channels), refund and ask them to give again; if we are wrong, that is a bug — do not "fix" the row, file S1 |
| 6 | Is the transaction `failed` but Paystack says success? | the transaction's stored reason and the event list | A `charge.failed` arrived after a `charge.success`? Impossible by design (final states never reopen) — but a `charge.failed` **before** a late `charge.success` for the same reference means the donor retried on the same reference; the second delivery is refused as final. Refund the second charge on Paystack |
| 7 | Nothing above, and `verify` on the callback did not settle either? | `php artisan tinker` → `app(App\Payments\PaymentManager::class)->verifyAndSettle($transaction)` | Reads Paystack's answer now and applies the same `settle()`. If Paystack says success and this settles it, the webhook was the only thing missing; go back to 2 |
| 8 | Still nothing | `scghf:reconcile-payments` | The daily sweep asks Paystack about every pending transaction in the look-back window and settles what it finds. Run it with `--execute` |

What never to do: edit `donations.status` or `payment_transactions` by
hand, or create a donation row to "match" the dashboard. Record the outcome
as a note on the donation; the audit log has to explain every state.

## 7. Configuration

| Key | Meaning |
|---|---|
| `PAYMENT_DRIVER` | `fake` (local, CI, staging without keys) or `paystack` |
| `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY` | `pk_test_`/`sk_test_` on staging, `_live_` only on the production server's `.env`. Site Health reads which |
| `PAYSTACK_WEBHOOK_SECRET` | the secret key of the same account; the HMAC key |
| `PAYSTACK_WEBHOOK_PATH` | must stay under `/webhooks/` (CSRF exemption is by prefix) |
| `PAYSTACK_WEBHOOK_IPS` | optional allow-list |
| `PAYSTACK_FEE_PERCENT`, `PAYSTACK_FEE_CAP_PESEWAS`, `PAYSTACK_FEE_FLAT_PESEWAS` | the *displayed* fee model for "cover the fee"; the ledger keeps what Paystack actually charged |
| `PAYMENT_WEBHOOK_MAX_ATTEMPTS` | job retries before `failed_jobs` |
| `PAYMENT_RECONCILIATION_ENABLED`, `_LOOKBACK_DAYS`, `PAYMENT_ABANDON_AFTER_MINUTES` | the sweep |
| `PAYMENT_ALERT_FAILED_PER_HOUR`, `PAYMENT_ALERT_REFUNDS_PER_DAY` | the anomaly lines |
| `RECURRING_MAX_FAILURES` | pause after this many failed monthly charges |

## 8. Tests that hold the line

`PaymentsTest` (webhook signature, replay, mismatch, final states),
`PaystackClientTest` (the wire), `GivingFlowTest`, `RecurringGivingTest`,
`ReconciliationTest`, `ShopCheckoutTest`, the refund tests in `PaymentsTest` and `FinanceAdminTest`, and the browser
suite's donation and purchase. Every one of them runs on every commit;
nothing in `app/Payments` merges without a test that fails without it.
