# Scout Call Buttons changelog

## 0.1.4 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

## 0.1.3 (2026-10-13)

- **Shorter Scout sidebar.** The shared admin kit (1.3.0) shows a long Scout menu as one sidebar link per group (Home, Search, Website, Business, Reviews, Chat; Business is new and holds hours, team, case results, cards, call buttons, booking and proposals), in the same order as the tabs on each screen. Every screen still works and is one tap away in the tabs; only the sidebar got shorter. Count bubbles (like unanswered chat questions) carry over to the group's link.

## 0.1.2 (2026-10-13)
- Ships admin kit 1.2.0: the Scout menu bar is grouped into five tabs (Overview, Search, Website, Reviews, Chat), with the open group's screens underneath.

## 0.1.1 (2026-10-13)
- Ships the current Scout admin kit (1.1.1), so the settings screen shows the shared section nav and the kit check passes.

## 0.1.0 (2026-10-13)
- First release. A call, text and "Let's talk" bar pinned to the bottom of the screen on phones (design 4b).
- "Let's talk" opens a sheet with numbered options: call, send a text, book a time. "Book a time" opens the booking page.
- Settings under Build → Call buttons: phone, texting number (or Text off), booking link, wording, up to three photos, pages to hide on.
- Phone and booking link fill in from Scout Core business info and the Scout booking page when present.
- Taps are counted through Scout Analytics as contact_call, contact_text, contact_book and contact_open.
- Moves the cookie notice and the chat button up so the bar never covers them.
