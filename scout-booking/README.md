# Scout Booking

Private sessions booked on the client's own site. Clients pick a session and a
time, answer a few short questions, and either send a request the owner
approves or pay a deposit (or the full price) at the WooCommerce checkout.
Every booking gets change and cancel links, a reminder, and a place in the
owner's phone calendar.

Built for a one-person practice: one calendar, no staff scheduling in 0.1.

## Where it lives

Its own **Bookings** item in the sidebar, with the Scout bolt (beside Members
and Events), and the Scout menu bar on every screen:

| Screen | What it does |
|---|---|
| Schedule | Requests to approve, the schedule by day or week, paid and unpaid, client details and intake answers, blocked time, the phone calendar link. |
| Session types | The sessions people can book, one plain form each. |
| Settings | Booking page, owner email, cancellation window, notice and how far ahead, checkout hold, pay window, booking hours, newsletter box. |

Bookings hold clients' personal details, so every screen needs the
`list_users` capability (administrators), the same rule as Scout Members.

## Session types

| Field | Notes |
|---|---|
| Name | Shown on the booking page and in emails. |
| Name on receipts | What the checkout, receipt and order show. Plain, neutral wording, such as "Private session, 2 hours", keeps statements clean with payment companies. Empty uses the name. |
| Length, buffer after | Minutes. The buffer keeps the next session from starting too soon. |
| Price | The session price. |
| Paid to book it | The full price, a deposit of a set amount (the rest at the session), or nothing online. |
| By request only | The client sends a request; the owner approves or declines; approval emails a pay link. For sessions where the owner talks with a client first. |
| Days and hours | The business hours (below), or the type's own days and hours. |
| Questions | Up to six short questions, each required or not, short or long answer. Keep health questions out; this form is not built to hold health records. |
| Note | A line or two shown before booking and in the client's emails. |

The plugin ships with no session types and no prices. The owner enters them.

## Working hours and open times

Open times are the working hours minus appointments, requests, checkout holds
and blocked time, minus anything inside the shortest notice or past the
furthest-ahead limit. Start times step every 15, 30 or 60 minutes.

Working hours come from, in order:

1. the session type's own days and hours, when set;
2. **Scout Hours**, when it is active and its hours are confirmed, holiday
   closures and special hours included;
3. the booking hours on Settings.

Any booking blocks every session type, because one person runs the practice.

## The booking page

```
[scout_booking]                    every session that is turned on
[scout_booking types="a1b2,c3d4"]  only some (ids are on Session types)
[scout_booking type="a1b2"]        one session, straight to picking a time
```

Three steps: session, time, your details. Each step is a plain page address,
so it works without scripts, with the back button and with a screen reader,
and the layout holds at 390px. The booking page is never cached. The first
published page that shows the form becomes the booking page for email links;
Settings can change it.

The details step asks for first and last name, email and phone (all required),
the type's questions, and an optional, unticked newsletter box. A hidden field
and a short time check stop most bots.

## Payments (WooCommerce)

With WooCommerce active, each session type that takes money online gets a
hidden, virtual, sold-one-at-a-time product, named with the receipt name and
priced at what is paid online. Saving a session type keeps the product in
step. Visiting the product page directly goes to the booking page, and the
product cannot be added to a cart except from a booking.

1. The client submits the form; the time is held (30 minutes by default).
2. The booking goes in the cart and the client lands on the checkout. The line
   shows the appointment and any balance due at the session. Name, email and
   phone are filled in from the booking.
3. Order placed and waiting for payment (on-hold or pending): the time stays
   held for the pay window.
4. Processing or completed: booked. The client gets the confirmation with a
   calendar file; the owner gets an email.
5. Cancelled or failed: a checkout booking is released. An approved request
   keeps its pay link. A booked session whose order is cancelled is cancelled
   and the client is told. Refunded: the booking is cancelled.

This plugin never sees payment details. The client sees whatever payment
methods the shop has turned on.

**Test mode until a payment service is connected.** Turn on WooCommerce's own
Cash on delivery method (WooCommerce, Settings, Payments), rename it "Test
payment", and tick "Accept for virtual orders". Orders then go straight to
Processing, which books the session. Turn it off when the real payment service
goes in.

**Discount codes** are WooCommerce coupons. To limit a code to one session,
pick that session's product under the coupon's usage restriction. The coupon
comes off what is paid online. For a code meant to discount the whole session
(such as 40% off a 60-minute massage), set that type to take the full price
online; with a partial deposit the discount applies to the deposit only, and
the balance due stays the price minus the deposit.

### Without WooCommerce

Every booking is a request, and the site says so: "Online payment is not on
yet, so every booking here is a request." The dashboard shows "Payments are
off". Approving a request books it, with the price paid at the session.

## Changing and cancelling

Every client email links to the client's booking page (a secret link), with
Add to calendar, Google Calendar, Change the time and Cancel.

- Outside the cancellation window (24 hours by default), clients move or
  cancel online. Requests can be moved or withdrawn the same way.
- Inside the window, clients are asked to email the owner (default), or the
  owner can let them cancel online with the deposit kept.
- Cancelling never refunds by itself. The owner email says when the client
  paid online, and the refund is made from the order in WooCommerce.

The policy line shown before booking and in every email is worked out from
the window ("Please give 24 hours of notice to change or cancel.") unless the
owner writes their own.

## Owner view

- Requests to approve, with intake answers and Approve / Decline.
- The schedule by day or week: time, session, status, client name, email and
  phone, paid and unpaid ("Deposit paid: $50.00 of $200.00. $150.00 due at
  the session."), coupon codes, the order link, intake answers, and Cancel.
- Block time: a day, a range of days, or hours on a day, with a note.
- The phone calendar link (a private calendar feed): subscribe to it in a phone calendar; it refreshes
  about hourly. "Make a new address" retires the old one.
- A count of requests on the sidebar item and in the Scout menu, and a line in
  Scout Events' "Needs you" list.

## Emails

Plain text, through `wp_mail()` (set up a sending service on the site).

| To the client | To the owner |
|---|---|
| We have your request | New session request |
| Approved, with the pay link | |
| About your request (declined) | |
| Booked, with a calendar file | Booked |
| New time, with a calendar file | Moved |
| Cancelled | Cancelled (by the client) |
| Time released (approved, not paid in time) | |
| See you soon (the day before) | |

An hourly check releases checkout holds that ran out, releases approved
requests unpaid after the pay window, and sends reminders.

## For other features

- `do_action( 'scout_newsletter_optin', $email, $first_name, 'booking', array(), $context )`
  fires when the newsletter box is ticked (Scout Newsletter listens on it and
  tags by the source, so no tags are sent). `$context` holds `wording` (the
  exact words beside the box), `url` and `page` (the page the form was on), and
  `campaign` (the session type's id). The wording and page are kept as the
  record of what the person agreed to.
- `scout_booking_created` ( `$row`, `$type` ) after a booking or request is made.
- `scout_booking_status` ( `$row`, `$status` ) after any status change.
- `scout_booking_types_saved` after session types change.
- `scout_booking_now` filters the clock (tests).

## What this feature needs from the theme

- Custom properties the booking steps read, each with a neutral fallback: `--scout-booking-accent`, `--scout-booking-on-accent`, `--scout-booking-line`, `--scout-booking-inset`, `--scout-booking-radius`, `--scout-booking-danger`. Set them on `.sb` or `:root`. Fonts and text color come from the theme.
- A page holding `[scout_booking]`. The first published page that shows the form becomes the booking page for email links, the call bar and the chat (`scout_booking_url()`). Settings can change it.
- Nothing else. `assets/scout-booking.css` loads only where the shortcode is, and the three steps are plain page addresses, so no template or script comes from the theme.

## Data

- Table `{prefix}scout_bookings`: one row per booking, request or blocked time.
- Options `scout_booking` (settings and the feed token), `scout_booking_types`,
  `scout_booking_db`.
- Personal data export and erase (Tools) cover bookings by email.
- Deleting the plugin removes the table and options. The WooCommerce products
  stay, because past orders point at them.

## Not in 0.1

- Card payments: a real payment service (Stripe, Square or PayPal) plugs into
  the WooCommerce checkout; nothing in this plugin changes.
- Staff and more than one calendar; group sessions; packages and gift cards.
- Two-way sync with Google or Apple calendars (the feed is one way).
- Text message reminders.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Booking details: first and last name, email, phone, the session and time picked, answers to the session's intake questions, whether the newsletter box was ticked, and the price, amount due, amount paid and order number | This website's database, in the bookings table (the Bookings screen) | Until someone deletes it. The hourly check only changes a booking's status and never deletes it. The WordPress personal data tools export a person's bookings by email address. The eraser deletes unpaid bookings and keeps paid ones as payment records, with the name, email, phone and answers taken out, and deleting the plugin removes the whole table. | Emailed to the site's own booking notice address (the notice address in Business info unless another is set), with the intake answers |
| Name, email and phone of each booking from the last 30 days and the next 400 days | The owner's private calendar feed, a secret address on this website | Built fresh each time the feed is read; nothing extra is stored | Whatever calendar app the owner adds the feed to |
| What the visitor typed in the booking form, when the form has to be sent back to fix a mistake (only when the form is sent back with a problem, such as a missing field) | A temporary record in this website's database | 10 minutes | Nobody |
| Email, first name, the page the form was on, the exact words beside the newsletter box, and the session type (only when the visitor ticks the newsletter box and Scout Newsletter is on) | Handed to the Scout Newsletter plugin. This plugin keeps no separate copy beyond the booking itself. | Not kept separately by this plugin | Scout Newsletter, which sends it to the newsletter service set there |
| Name, email, phone, the appointment time and the booking's code (only when WooCommerce is on and the session takes a payment online) | WooCommerce's cart, checkout and order records on this website | As long as WooCommerce keeps the order. This plugin never deletes orders. | Nobody |

This plugin sets no cookies itself. When a session takes payment, WooCommerce sets its own cart and session cookies and keeps the order under its own rules.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
