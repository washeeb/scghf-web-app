# 13. Deliveries and couriers

The foundation's own riders and courier agents confirm each delivery from
their phone, and the customer is told at every step. The office's side is
in this panel; the rider's side is a small site at **/courier** that works
on any phone browser and can be added to the home screen like the rest of
the site.

## Setting up a courier

*Staff accounts → New account.* Give the person the **Courier** role and
nothing else. That makes a *public* account: they sign in at the site's
own **Sign in** (not this panel), land straight on their deliveries, and
cannot open this panel at all. They get the usual email to set their
password. To stop a courier, switch the account off or remove the role.

A courier who is also office staff is unusual; if it happens, give them
their office role here as well and they will sign in at the panel like
other staff, with two-factor.

## Handing an order to a courier

Open the order (*Shop → Orders*). Once it is paid and is for delivery
rather than collection, the header has **Assign a courier**: pick the
rider, add a note if there is one ("call before you set off", "gate code
1234"), and save. The rider is emailed the address and a link. The order's
history records who it went to.

**Dispatched** is still there for a courier company that is not on the
system (DHL, a bus station parcel office): it records the courier's name
and tracking reference and tells the customer, with nobody to confirm the
door.

*Shop → Deliveries* lists every delivery: who has it, since when, and how
many attempts. **Reassign** moves it to another rider; **Cancel delivery**
takes it off the rider's list without changing the order. The number on
the menu is deliveries that could not be made and are waiting on you.

## What the rider does

On their phone the rider sees the deliveries in their hands, oldest first,
with the address, a **Call** button and a **Map** button. Then, in order:

1. **I have picked it up** — the order becomes *Dispatched* and the
   customer is emailed and texted that it is on its way, with the rider's
   name.
2. **On my way to the customer** — *Out for delivery*; the customer is told.
3. **Delivered — confirm** — who received it, an optional note, an optional
   photograph of the parcel at the door (the phone's camera opens
   directly), and the phone's position if the phone allows it. The order
   becomes *Delivered* and the customer is told.
4. Or **I could not deliver** — a reason from the list, and the office is
   emailed. The delivery stays with the rider for another attempt unless
   you reassign or cancel it.

The proof — name, note, photograph, position — is on the order page under
**Courier**. The photograph is private: only the rider who took it and
staff who can see deliveries can open it.

## What you can change

*Site settings → Courier portal* holds every word the rider reads, the
list of reasons a delivery can fail, and whether a photograph is
**required** to confirm a delivery (off by default — a rider in the rain
with a customer at the door should not be blocked by a camera).
