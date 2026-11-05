# Scout Booking changelog

## Unreleased (2026-11-05)

- **Busy times from your calendar.** Settings has a new box for the private address of up to three calendars (the iCal address Google, Apple and Outlook all offer; Google: Settings, your calendar, "Secret address in iCal format"). Every 15 minutes, in the background, Booking reads them and stops offering times you're busy, keeping the session's buffer clear on both sides. Events marked free, cancelled events and all-day events marked free don't block anything. Repeating events (daily, weekly by day, monthly, with end dates, counts and skipped dates) are handled. Only start and end times for the next 60 days are kept: never titles, people, places or notes. Bookings already made are not touched.
- **The address stays private.** It's kept out of the settings that load on every page, never shown back in full (only the host and the last 6 characters, with Replace and Remove), never logged, and never in an export. Deleting the plugin removes it.
- **Status in words.** Settings and the Schedule status line say "Calendar connected. Last read 4 minutes ago, 12 busy times in the next 60 days." or, when a read fails, "Couldn't read your calendar since Nov 5, 2:10 pm. Bookings still open, but busy times may be missing." After a full day of failed reads, Site status lists it under WordPress checks, with a link to the address.
- **Video call link per session type.** An optional fixed Google Meet or Zoom room goes in the booked, moved and reminder emails and in the calendar invite (location and link). Meet links aren't made for you.

## 0.2.1 (2026-11-05)

- **Privacy tools keep paid bookings.** WordPress's Erase Personal Data tool still deletes a person's unpaid bookings. A booking that took money now stays as the payment record, with the name, email, phone and answers taken out, and the tool says so. The export also lists the amount paid, the order number and whether the newsletter box was ticked.

## 0.2.0 (2026-11-05)

- **Shared admin kit 1.11.0.** Plain menu names: To do, Business, People, Site features, Search, Legal pages and Site status (labels only, the group keys are the same). Ligatures on where the font has them, and one shared copy of the menu finder.
- **Booking link for the whole site.** Scout Booking now gives the site its booking page address (`scout_booking_url()`), so Call buttons, Chat and Free site check send "Book" to your booking page. It only does this once a booking page is set and published, and a theme that already has its own booking link keeps it.
- **One newsletter ask per booking.** With Scout Newsletter on, a checkout that only pays for bookings skips Newsletter's checkout box when the booking form already asked. One box, one consent record.
- **Notices go to the shared address.** When "Send booking emails for you to" is blank, booking emails now go to the notice address in Business info instead of the site email (the site email is still used without Scout Core). Clients see that address when a change is too late to make online.
- **Plainer words.** The browser tab says Bookings. Schedule's title matches its menu. "Booking settings" is now "Settings". "Your calendar feed" is now "Phone calendar link". Long help text is cut to one short line.

## 0.1.5 (2026-11-05)

- **Schedule leads with a status line:** how many requests are waiting, or that none are, plus your next booking in the coming week.
- **No more blue buttons on Schedule.** Approve and Block this time are outlined, Decline is a quiet text button, and Day and Week are a pair: the one you are on is outlined and marked current, the other is quiet.
- **The booking form shortcodes moved into a closed box** at the bottom of the side column. Nothing about requests, blocking time or the calendar feed changed.
- **Shared admin kit 1.10.0.** Side-by-side boxes stack by the width of the main column, not the window, so nothing squeezes beside the section list. The kit adds one status line for the top of a screen, list rows and a closed More options box.

## 0.1.4 (2026-11-04)

- **Files by the redesigned Scout menu: Home, Inbox, Reports, Your business, Your website, Get found, Policies, Site health and Scout tools.** Shared admin kit 1.8.0. Staff (anyone who cannot change site settings) see Home, Inbox and Your business. Greyed "Turn on" items show for operators only, so clients only see what their site has. Scout tools reads Build tools on white-label sites (dashboard redesign, Joy 2026-11-04).

## 0.1.3 (2026-11-04)

- **Files by the new Scout menu map (Overview, Your business, Content, Search and AI, Site health, Inbox, Build).** Shared admin kit 1.7.0: the same seven items on every site, the Build group hidden from everyone who is not an operator (Site Standard §7 v1.2, Joy 2026-11-04).

## 0.1.2 (2026-11-04)

- **Updates group in the shared Scout nav, and every group always shows.** Site Sync and the Change log sit under Updates (Scout → Updates holds every way of updating the site), Spam protection under Website, and the Events, Members, Partners, RV Park, Newsletter and CRM screens under Business. The seven groups show on every site; one with nothing on is greyed with a Turn on hint. Shared admin kit 1.6.0 (Scout Site Standard rules 11 and 12).

## 0.1.1 (2026-11-04)

- **Updates come from the new Scout GitHub organization.** The Scout repos moved from joyrmac to Scout-Media-Raleigh on 2026-11-04. The update check, the release feed and the plugin details now use `Scout-Media-Raleigh/Scout-Plugins`. GitHub still forwards the old address, which is how this release reached the site.

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
  the `scout_newsletter_optin` action with source `booking`, no tags, and a
  context of the exact box wording, the page (`url` and `page`) and the
  session type's id as `campaign`.
- **Without WooCommerce** every booking is a request, clearly labelled on the
  site and in the dashboard; approval books it with nothing paid online.
- Personal data export and erase for bookings.
