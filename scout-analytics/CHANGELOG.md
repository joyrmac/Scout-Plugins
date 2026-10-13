# Scout Analytics changelog

## 0.1.5 (2026-10-13)

- **Shorter Scout sidebar.** The shared admin kit (1.3.0) shows a long Scout menu as one sidebar link per group (Home, Search, Website, Business, Reviews, Chat; Business is new and holds hours, team, case results, cards, call buttons, booking and proposals), in the same order as the tabs on each screen. Every screen still works and is one tap away in the tabs; only the sidebar got shorter. Count bubbles (like unanswered chat questions) carry over to the group's link.

## 0.1.4 (2026-10-13)

- **Cleaner Scout menu bar.** The shared admin kit (1.2.0) sorts the screens into five groups (Overview, Search, Website, Reviews, Chat). The open group's screens show as tabs underneath, so a site with many features no longer gets a wall of links, and on a phone the bar fits on one line.

## 0.1.3 (2026-10-13)

- **Fix: the section nav was unstyled on theme screens.** A theme carrying an older copy of the admin kit loaded its old stylesheet. The kit (1.1.1) now always loads the stylesheet that matches its own code.

## 0.1.2 (2026-10-12)

- **One section nav on every Scout screen.** The shared admin kit (1.1.0) prints the same row of pills on every screen in the Scout menu (Features, Site health, Business info, SEO, AI search, Image optimizer and so on), read live from the sidebar, so the screens read as one product and a new feature appears in the row by itself.

## 0.1.1 (2026-10-12)

- **The events list is complete and simpler to set up.** It now names every
  event the cards and link page send (card_links_click and links_card_click
  were missing) and explains the one regex trigger that catches them all,
  instead of a trigger per event. It no longer says booking and chat send
  events, since they don't yet.

## 0.1.0 (2026-10-12)

- First release. Loads Google Tag Manager (or, without a container, a GA4 tag)
  on the live site only, with Google consent mode defaults set first.
- Cookie notice: count with opt out (default) or ask first. Global Privacy
  Control counts as a no. `[scout_privacy_choices]` reopens it.
- One click-reporting API for Scout features: `data-scout-track`,
  `data-scout-view` and `window.scoutTrack()`.
- Settings under Scout Build / Site Build → Analytics.
