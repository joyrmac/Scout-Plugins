# Scout Call Buttons

A bar pinned to the bottom of the screen on phones with **Call**, **Text** and **Let's talk**.
"Let's talk" opens a sheet with three numbered choices: call now, send a text, book a time.
Design: Scout features DESIGN.md 5.9 (screen 4b).

- **Settings:** Build → Call buttons (Settings → Call buttons on sites without a Build section).
- **Phones only.** Hidden above 760px wide.
- **Defaults:** phone from Scout Core business info; booking link from `scout_booking_url()` (Scout Booking's page, or the Scout theme's own `/book/`).
- **Hidden on** `card`, `links`, `review` and `book` pages by default, since those carry their own buttons.
- **Text:** turn it off for landlines. Only promise a reply time the client will keep.
- **Booking:** opens the booking link. Times are not shown inline, because the calendars we use do not share open times with other sites.
- **Tracking:** with Scout Analytics on, taps send `contact_call`, `contact_text`, `contact_book`, `contact_open`.
  In Tag Manager, widen the click trigger regex to `^(card|links|contact)_`.
- **Filter:** `scout_contact_active` (bool) turns the bar on or off for the current page.

## What this feature needs from the theme

- **DESIGN.md:** the sheet reads the DESIGN.md roles surface, surface 2,
  text, muted text, line, accent, accent fill, on accent and the body and label
  fonts, as `--scout-surface`, `--scout-surface-2`, `--scout-text`,
  `--scout-text-2`, `--scout-line`, `--scout-accent`, `--scout-accent-fill`,
  `--scout-on-accent`, `--scout-font-body` and `--scout-font-label` (links use
  `--scout-accent-text` when a DESIGN.md adds `accent_text`, else
  `--scout-accent`). These are the `--scout-*` variables `tools/design-build.php` writes to the theme's `assets/css/design-tokens.css` from DESIGN.md, so the theme loads that file. The names it read before still win when the theme
  sets them: `--surface`, `--surface-2`, `--text`, `--text-2`, `--line`,
  `--accent`, `--accent-fill`, `--on-accent`, `--accent-text`, `--font-mono`,
  `--font-sans`. With neither, Scout's dark palette. The bar itself is dark
  glass with light text on every site.
- **Where it lands:** printed through `wp_footer()` and fixed to the bottom of
  the screen on phones (12px from the sides, 14px above the bottom plus the
  phone's safe area, `z-index: 9997`). Hidden from 761px up.
- **Room the theme leaves:** the plugin adds `scc-on` to the body class and
  pads the bottom of the page by 84px on phones, so the theme prints
  `<body <?php body_class(); ?>>` and pins nothing of its own to the bottom of
  the screen. The cookie notice and the chat button move up to clear the bar.
- **Facts:** the phone from Business info (`scout_core_business()`) and the
  booking link from `scout_booking_url()`. The theme types neither.
- **scout-setup.json:** nothing. The sheet's photos are picked on the Call
  buttons screen.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Taps on the Call, Text, Book a time and Let's talk buttons (which button was tapped, never who tapped it) (only when Scout Analytics is on) | Passed to Scout Analytics on the page | Not kept by this website | Google Analytics (Google), through Scout Analytics |

The call bar stores nothing and sets no cookies. Call and Text open the visitor's own phone app.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
