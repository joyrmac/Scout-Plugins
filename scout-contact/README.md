# Scout Call Buttons

A bar pinned to the bottom of the screen on phones with **Call**, **Text** and **Let's talk**.
"Let's talk" opens a sheet with three numbered choices: call now, send a text, book a time.
Design: Scout features DESIGN.md 5.9 (screen 4b).

- **Settings:** Build → Call buttons (Settings → Call buttons on sites without a Build section).
- **Phones only.** Hidden above 760px wide.
- **Defaults:** phone from Scout Core business info; booking link from Scout Booking's page when there is one.
- **Hidden on** `card`, `links`, `review` and `book` pages by default, since those carry their own buttons.
- **Text:** turn it off for landlines. Only promise a reply time the client will keep.
- **Booking:** opens the booking link. Times are not shown inline, because the calendars we use do not share open times with other sites.
- **Tracking:** with Scout Analytics on, taps send `contact_call`, `contact_text`, `contact_book`, `contact_open`.
  In Tag Manager, widen the click trigger regex to `^(card|links|contact)_`.
- **Filter:** `scout_contact_active` (bool) turns the bar on or off for the current page.

## What this feature needs from the theme

- Tokens the bar and sheet read, each with a fallback: `--surface`, `--surface-2`, `--text`, `--text-2`, `--line`, `--accent`, `--accent-fill`, `--on-accent`, `--accent-text`, `--font-sans`, `--font-mono`.
- A `wp_footer()` call. The bar prints there on every page.
- A booking page. "Book a time" reads Scout Booking's page through `scout_booking_url()`, or the link typed on the Call buttons screen. With neither, the sheet offers call and text only.
- A header that shows the phone number and a booking button on wide screens. The bar hides above 760px and expects the header to take over.
- Nothing to call. The `scout_contact_active` filter turns the bar off for a page, and it stays off on the card, links, review and book addresses by default.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Taps on the Call, Text, Book a time and Let's talk buttons (which button was tapped, never who tapped it) (only when Scout Analytics is on) | Passed to Scout Analytics on the page | Not kept by this website | Google Analytics (Google), through Scout Analytics |

The call bar stores nothing and sets no cookies. Call and Text open the visitor's own phone app.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
