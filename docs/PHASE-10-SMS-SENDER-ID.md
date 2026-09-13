# SMS in Ghana: sender ID registration and the provider choice

## Why this document exists

Since 2021 the National Communications Authority (NCA) requires every
alphanumeric sender ID used on Ghanaian networks (MTN, Telecel, AirtelTigo)
to be registered before it will be delivered. **An unregistered sender ID is
not rejected — it is silently dropped.** The gateway reports "accepted", the
log says "sent", and nothing arrives. This is the single most common reason
a Ghanaian charity's receipts "stop working" after a provider change.

The foundation's registered ID is **`GreaterHope`** (confirmed 2026-09-04,
mNotify). It is exactly eleven characters, which is the GSM maximum.

## Registering (or re-registering) a sender ID

Registration is done **through the provider**, not directly with the NCA.
Each provider files with the networks on the foundation's behalf.

1. **Choose the ID.** Alphanumeric, 3–11 characters, letters and digits, no
   spaces or punctuation. It should be recognisably the foundation:
   `GreaterHope` is; `SCGHF` would be filed as a second ID if ever needed.
2. **Prepare the documents** every provider asks for:
   - Certificate of incorporation / registration (Department of Social
     Welfare registration for an NGO)
   - A letter on the foundation's headed paper, signed by a director,
     requesting the sender ID and describing what it will be used for
     ("donation receipts, order updates, event reminders and updates to
     supporters who have opted in")
   - The director's Ghana Card
   - Sample messages, one per use (the seeded SMS templates are the samples)
3. **Submit on the provider's dashboard** (mNotify: *Sender IDs → Register*;
   Arkesel: *Sender ID → Request*; Hubtel: through the account manager).
   Approval takes 2–10 working days; MTN is usually the slowest.
4. **Confirm delivery on every network** before relying on it: send the
   `order.shipped` test from *Communications → SMS templates → Send a test*
   to one MTN, one Telecel and one AirtelTigo number. Three "sent" rows in
   the SMS log and three phones that buzzed.
5. **Record the approval reference** in the provider's dashboard notes and in
   `docs/` — if the ID is ever challenged, that is the evidence.

Two rules the software enforces so nobody has to remember them:

- `SMS_SENDER_ID` longer than 11 characters is refused at save.
- A collapse in delivery rate (`scghf:sms-delivery-reports`) raises an
  alert, because that is what a de-registered ID looks like from here.

## Providers

| Provider | Route | Balance | Delivery reports | Notes |
|---|---|---|---|---|
| **mNotify** (current) | local | credits | yes, polled hourly + webhook | Sender ID registered. Prepaid; top up before the balance line (`SMS_LOW_BALANCE_THRESHOLD`). |
| **Arkesel** | local | credits | yes | The fallback with the same shape. Register the sender ID separately — registrations are per provider. |
| **Hubtel** | local | not on the SMS API | yes | Postpaid to a merchant account; check the balance on their dashboard. Highest volume aggregator. |
| **Twilio** | international | money (USD) | yes | Fallback only: several times the local rate, and alphanumeric IDs are not guaranteed on every Ghanaian network — use a messaging service or a number. |

Switching provider is *Settings → Email & SMS → SMS provider* once that
provider's keys are in `.env`. Nothing is redeployed. The health page and
the daily balance alert follow the chosen provider.

## Opt-out and consent

- Marketing texts (`sms.broadcast`, `donation.abandoned`-style follow-ups)
  go only to people who ticked "send me updates by SMS", and wait for quiet
  hours (21:00–07:00) to end.
- A reply of **STOP** is honoured at once by the delivery webhook and the
  number is added to the do-not-contact list (*Communications → Do-not-contact
  list*). Staff can add a number by hand; releasing one needs a reason and
  is audited.
- Transactional texts — a receipt, an order update, a reminder for an event
  somebody registered for — are not marketing and go regardless of the
  marketing opt-in, but never to a number suppressed for everything.

## Cost control

- Every template and broadcast is measured on save: characters, encoding,
  segments. A cedi sign forces the expensive encoding (70 characters per
  segment instead of 160); the meter says so.
- `SMS_MAX_SEGMENTS` (default 2) refuses longer messages outright.
- `SMS_COST_PER_SEGMENT_MINOR` is the contracted rate in pesewas; the
  estimate on every broadcast and the monthly total in the SMS log use it.
  Replace it with the real rate from the provider contract.
