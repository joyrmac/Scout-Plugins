# Scout Analytics changelog

## 0.1.14 (2026-10-16)

- **The Plugins screen shows auto-updates as on.** The updater now reports the newest release even when the plugin is already on it, so WordPress lists Scout plugins as up to date and shows "Auto-updates enabled" instead of a blank column and an "Auto-updates Disabled" count. Scout plugins were already updating themselves; only the label was wrong.

## 0.1.13 (2026-10-16)

- **Whose site is this, at a glance.** Every Scout screen shows the client's logo (theme logo, else Site Icon) and name top right, beside the Scout mark, so a Scout screen never leaves you guessing which client's site you are on. Joy asked for "them and us together". Shared admin kit 1.5.0.

## 0.1.12 (2026-10-15)

- **Fix: the cookie notice's OK button passes contrast on themes without an accent color.** Its fallback was the bright Scout blue `#0E8FE6`, and white text on it is 3.45:1 (Hair by Patrick flagged it). The fallback is now the deeper `#0A78C2`, 4.69:1. Themes that set `--accent` keep their own color.

## 0.1.11 (2026-10-14)

- **Faster first screen: Tag Manager loads after the page shows.** Tag Manager and GA4 are about 285 KB of script; loading them in the head cost scoutraleigh.com most of its mobile PageSpeed score (90 to 68). The consent defaults still set first, then the tag loads once the page has loaded (when the browser is idle, within 3 seconds) or at the visitor's first tap, scroll or key. On by default; Scout → Search → Analytics → Page speed turns it off. A visitor who leaves in the first second or two is not counted.

## 0.1.10 (2026-10-13)

- **Fix: missed updates on shared hosts.** Version checks read one version list the Release workflow publishes as a plain download, instead of GitHub's API, which allows only 60 calls an hour per server address and ran out on WP Engine. The API stays as a fallback.

## 0.1.9 (2026-10-13)

- **Fix: the cookie notice's OK button is readable on every theme.** Its text used a fixed white, which failed contrast on sites whose accent is light (North Crest's gold: 1.6:1). It now uses the theme's `--on-accent` color, falling back to white, so a theme with a light accent sets `--on-accent` to a dark color.

## 0.1.8 (2026-10-13)

- **No dead ends in the Scout menu.** WordPress's own list screens inside the shortened Scout menu (the Reviews list, Chat questions, team members, case results) now show the same tabs as every Scout screen, so Review inbox, Ask for reviews and Review settings are one tap away from the Reviews list. Shared admin kit 1.4.0.

## 0.1.7 (2026-10-13)

- **Fix: newer versions were being missed.** GitHub lists releases by tag name as text, so "0.4.9" came before "0.4.11" and the updater offered the wrong one (Scout Reviews stopped at 0.4.9). It now compares version numbers and reads up to three pages of releases instead of one.

## 0.1.6 (2026-10-13)

- **Fix: no more "Bad gateway" from update checks.** Every Scout plugin used to download the same release list from GitHub on its own, one after another (up to 10 seconds each). When those caches expired, one dashboard page could wait on all of them, past WP Engine's 60-second limit, and show a 502 "Bad gateway". The plugins now share one download, cached for six hours; a failed download is retried after 15 minutes instead of hiding updates for six hours.

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
