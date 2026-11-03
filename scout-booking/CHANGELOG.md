# Scout Booking changelog

## 0.1.0 (2026-11-03)

- First release. Private sessions booked on the client's own site, up to the
  payment step: every flow runs end to end with WooCommerce and an offline
  test payment method; a real payment service plugs into the same checkout.
- **Session types** under Bookings, Session types: name, name on receipts,
  length, price, what is paid to book (the full price, a deposit, or nothing
  online), buffer after, its own hours or the business hours, "by request
  only", up to six short intake questions, and a note shown before booking.
- **Open times** from the working hours minus appointments, requests, checkout
  holds and blocked time, with a shortest notice, a furthest-ahead limit and a
  start-time step. Working hours come from the session type, else Scout Hours
  when its hours are confirmed (holiday closures included), else the booking
  hours on Settings. One-person practice: any booking blocks every type.
- **`[scout_booking]`** (with `type` and `types`): pick a session, pick a day
  and time, then details and intake. Each step is a plain page address, so it
  works without scripts and with the back button. Built for 390px phones.
- **By request:** the owner gets an email, approves or declines on the Bookings
  screen, and approval emails the client a link to pay and book. Unpaid
  approvals are released after the pay window (48 hours by default).
- **Deposits through WooCommerce:** a hidden virtual product per session type,
  named with the receipt name and priced at what is paid online; the cart line
  shows the appointment and the balance due. The time is held during checkout
  (30 minutes by default) and while an offline payment is waiting. Paid orders
  book the session; cancelled or failed orders release it.
- **Discount codes** are WooCommerce coupons, limited to a session's product as
  usual (tested with a 40% FIRST code on the 60-minute product).
- **Emails** in plain text: request received, new request, approved with pay
  link, declined, booked (with a calendar file attached), moved, cancelled,
  time released, and a reminder the day before.
- **Change and cancel links** for clients, inside a cancellation window
  (24 hours by default). Inside the window clients email the owner, or the
  owner can let them cancel online and keep the deposit.
- **Owner view:** requests to approve, a day or week schedule with paid and
  unpaid, client name, email and phone, intake answers, coupon codes and the
  order link; block time; cancel a booking.
- **Private calendar feed** (`?scout_booking_feed=` with a secret token, new
  address on request) for the owner's phone calendar.
- **Newsletter opt-in:** an unticked box that hands the email to
  the `scout_newsletter_optin` action ( `$email`, `$first_name`, `$source`,
  `$tags`, `$context` ), with the exact box wording and the page in `$context`.
- **Without WooCommerce** every booking is a request, clearly labelled on the
  site and in the dashboard; approval books it with nothing paid online.
- Personal data export and erase for bookings.
