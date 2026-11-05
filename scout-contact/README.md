# Scout Call Buttons

A bar pinned to the bottom of the screen on phones with **Call**, **Text** and **Let's talk**.
"Let's talk" opens a sheet with three numbered choices: call now, send a text, book a time.
Design: Scout features DESIGN.md 5.9 (screen 4b).

- **Settings:** Build → Call buttons (Settings → Call buttons on sites without a Build section).
- **Phones only.** Hidden above 760px wide.
- **Defaults:** phone from Scout Core business info; booking link from the Scout theme's `/book/` page.
- **Hidden on** `card`, `links`, `review` and `book` pages by default, since those carry their own buttons.
- **Text:** turn it off for landlines. Only promise a reply time the client will keep.
- **Booking:** opens the booking link. Times are not shown inline, because the calendars we use do not share open times with other sites.
- **Tracking:** with Scout Analytics on, taps send `contact_call`, `contact_text`, `contact_book`, `contact_open`.
  In Tag Manager, widen the click trigger regex to `^(card|links|contact)_`.
- **Filter:** `scout_contact_active` (bool) turns the bar on or off for the current page.
- **Restyle:** the sheet reads the Scout design tokens: `--scout-surface`, `--scout-surface-2`, `--scout-text`, `--scout-text-2`, `--scout-line`, `--scout-accent`, `--scout-accent-fill`, `--scout-on-accent`, `--scout-font-body`, `--scout-font-label` and the `--scout-radius-*` set. Accent text reads `--scout-accent-fill` until Scout Core writes `--scout-accent-text`. The theme's design build (`php tools/design-build.php <theme>`) writes these to `assets/css/design-tokens.css`. The older theme names (`--surface`, `--text`, `--accent`, `--accent-fill`...) still work as a fallback for one release. Override any `--scc-*` variable on `.scc` to restyle by hand.

<!-- visitor-data:start -->
## Visitor data

| What | Where it is kept | How long | Shared with |
|---|---|---|---|
| Taps on the Call, Text, Book a time and Let's talk buttons (which button was tapped, never who tapped it) (only when Scout Analytics is on) | Passed to Scout Analytics on the page | Not kept by this website | Google Analytics (Google), through Scout Analytics |

The call bar stores nothing and sets no cookies. Call and Text open the visitor's own phone app.

Source: `visitor-data.json`. Edit that file, then run `php tools/visitor-data.php`.
<!-- visitor-data:end -->
