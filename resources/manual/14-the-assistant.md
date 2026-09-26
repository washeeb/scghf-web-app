# 14. The assistant, and chat on WhatsApp

Two additions to the live chat in chapter 12: an automated assistant that
can answer the first questions, and the foundation's WhatsApp number
arriving in the same inbox. Both are off until somebody turns them on.

## What the assistant is, and what it is not

It answers from the foundation's **own published pages and FAQs** — nothing
else. It cannot see a donation, an order, a delivery, a beneficiary or an
account, and it is told to say so and fetch a person rather than guess. It
is not a member of staff and never claims to be: the first line of every
chat says plainly that the replies are automatic, and a **Talk to a person**
button sits on the screen the whole time it is answering.

By default it answers **only when nobody has the chat inbox open** — a
person while the office is working, an assistant at midnight. You can have
it answer everything first, under *Site settings → AI assistant*.

## What it always refuses

These never reach the assistant. The rule fires on the words the visitor
used, before anything is sent anywhere:

- **Anything about a child or somebody's safety** — abuse, neglect, a child
  at risk. It says nothing about it and passes the chat to safeguarding.
- **An emergency.** It shows the emergency line (*Site settings → AI
  assistant → Said first in an emergency*, which has the police and
  ambulance numbers in it — check they are right) and fetches a person.
- **A particular donation, receipt, order, refund or payment.** It cannot
  see any of them.
- **A complaint, anything legal, a journalist.**
- **Somebody asking for a person**, however they word it.

Each of those goes to the **department** it belongs to — the same
departments the contact form uses, with the same addresses and the same
target reply times — and that department is emailed with the conversation
so far. You can add to any of these word lists in the settings; use the
words your visitors actually type.

It also stops by itself after **eight answers** in one conversation
(changeable), on the principle that a visitor going round a ninth time is
not being helped.

## Switching it on

1. Somebody technical puts an API key in `.env` and sets `AI_DRIVER` and
   `FEATURE_CHAT_AGENT`. Until that is done, everything below is visible
   and has no effect — every chat goes to a person, exactly as before.
2. *Site settings → AI assistant* → **Let the assistant answer first**.
3. Read through the rest of that page. Give it a name that cannot be
   mistaken for a member of staff, check the disclosure line, check the
   emergency numbers, and write two or three sentences of **How it should
   sound**.

## Watching it

*Inbox → Assistant* is the screen to open once a week. It shows whether it
is answering, what it has cost this month against the ceiling, how many
chats it answered and how many it passed on, and — the important part —
**answers somebody marked wrong**.

To mark one: open the chat, hover the assistant's reply, press **This
answer was wrong**, and it appears on that screen. Do it whenever you see
one. An assistant nobody reviews is an assistant nobody can defend.

The list of **why a person was needed** is worth reading as a to-do list
rather than a fault report. A reason near the top every month usually means
a page needs writing, not that the assistant is failing.

### The money

There is a **ceiling for the month**, in cedis, set by whoever configures
the key. When the month's estimated spend reaches it, the assistant stops
and every chat goes to a person — it does not carry on and send a bill. The
figure on the screen is an estimate from the configured rates, not the
invoice.

## Chat on WhatsApp

*Site settings → Live chat → Answer WhatsApp here too.*

A message to the foundation's WhatsApp number becomes a chat in this same
inbox, with the same assistant in front of it and the same rules. Reply in
the chat screen and it goes back to their phone. Nothing is ever sent to a
number that has not written to us first.

**Meta's 24-hour rule.** WhatsApp only allows a free reply within 24 hours
of the visitor's last message. After that, a reply can only go as a
template Meta has approved in advance — the chat screen tells you when a
conversation is past the window. If the `chat.reply` template has not been
approved yet, a reply sent past the window is recorded as **not sent**,
with the reason, rather than quietly disappearing.

Setting it up needs Meta's side done first: a verified business, a WhatsApp
number, the webhook pointed at this site, and the `chat.reply` template
submitted and approved (*Communications → WhatsApp templates*). Until all
of that exists the switch does nothing.

## What is kept

The conversations are chats like any other and follow the same rule as
chapter 12: **twelve months** from the last line, then deleted. The record
of what the assistant cost is kept separately and holds no words from any
conversation — only the date, the size of the request and the price.
