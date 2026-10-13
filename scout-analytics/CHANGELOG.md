# Scout Analytics changelog

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
